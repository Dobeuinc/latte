<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Compiler;

use Latte\Compiler\Nodes\Php as Nodes;
use Latte\Compiler\Nodes\Php\Expression;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\Scalar;
use Latte\Context;
use Latte\Strict;


/**
 * PHP printing helpers and context.
 * The parts are based on great nikic/PHP-Parser project by Nikita Popov.
 */
final class PrintContext
{
	use Strict;

	public array $paramsExtraction = [];
	public array $blocks = [];

	private $exprPrecedenceMap = [
		// [precedence, associativity] (-1 is %left, 0 is %nonassoc and 1 is %right)
		Expression\PreOpNode::class              => [10,  1],
		Expression\PostOpNode::class             => [10, -1],
		Expression\UnaryOpNode::class            => [10,  1],
		Expression\CastNode::class               => [10,  1],
		Expression\ErrorSuppressNode::class      => [10,  1],
		Expression\InstanceofNode::class         => [20,  0],
		Expression\NotNode::class                => [30,  1],
		Expression\TernaryNode::class            => [150,  0],
		// parser uses %left for assignments, but they really behave as %right
		Expression\AssignNode::class             => [160,  1],
		Expression\AssignOpNode::class           => [160,  1],
	];

	private $binaryPrecedenceMap = [
		// [precedence, associativity] (-1 is %left, 0 is %nonassoc and 1 is %right)
		'**'  => [0, 1],
		'*'   => [40, -1],
		'/'   => [40, -1],
		'%'   => [40, -1],
		'+'   => [50, -1],
		'-'   => [50, -1],
		'.'   => [50, -1],
		'<<'  => [60, -1],
		'>>'  => [60, -1],
		'<'   => [70, 0],
		'<='  => [70, 0],
		'>'   => [70, 0],
		'>='  => [70, 0],
		'=='  => [80, 0],
		'!='  => [80, 0],
		'===' => [80, 0],
		'!==' => [80, 0],
		'<=>' => [80, 0],
		'&'   => [90, -1],
		'^'   => [100, -1],
		'|'   => [110, -1],
		'&&'  => [120, -1],
		'||'  => [130, -1],
		'??'  => [140, 1],
		'and' => [170, -1],
		'xor' => [180, -1],
		'or'  => [190, -1],
	];
	private int $counter = 0;
	private string $contentType = Context::Html;
	private ?string $context = null;
	private ?string $subContext = null;


	/**
	 * Expands %line, %dump, %raw, %args, %escape(), %modify() in code.
	 */
	public function format(string $mask, mixed ...$args): string
	{
		if (str_contains($mask, '%modify')) {
			$modifier = array_shift($args);
			$mask = preg_replace_callback(
				'#%modify(Content)?(\(([^()]*+|(?2))+\))#',
				function ($m) use ($modifier) {
					$var = substr($m[2], 1, -1);
					if (!$modifier) {
						return $var;
					}
					return $m[1]
						? $modifier->printContent($this, $var)
						: $modifier->print($this, $var);
				},
				$mask,
			);
		}

		$mask = preg_replace_callback(
			'#%escape(\(([^()]*+|(?1))+\))#',
			fn($m) => Expression\FilterNode::escapeFilter(null)->print($this, substr($m[1], 1, -1)),
			$mask,
		);

		$pos = 0;
		return preg_replace_callback(
			'#([,+]?\s*)?%(\d+\.|)(dump|raw|args|line)(\?)?(\s*\+\s*)?()#',
			function ($m) use ($args, &$pos) {
				[, $l, $source, $format, $cond, $r] = $m;
				$arg = $args[$source === '' ? $pos++ : (int) $source];

				switch ($format) {
					case 'dump':
						$code = PhpHelpers::dump($arg);
						break;
					case 'raw':
					case 'args':
						$code = $arg instanceof Node ? $arg->print($this) : (string) $arg;
						if ($cond && ($code === '[]' || $code === '')) {
							return $r ? $l : $r;
						} elseif ($format === 'args' && $arg instanceof Expression\ArrayNode) {
							$code = substr($code, 1, -1);
						}
						break;
					case 'line':
						$l = trim($l);
						$code = $arg ? " /* line $arg */" : '';
						break;
				}

				return $l . $code . $r;
			},
			$mask,
		);
	}


	public function generateId(): int
	{
		return $this->counter++;
	}


	public function setContentType(string $type): static
	{
		$this->contentType = $type;
		$this->context = null;
		return $this;
	}


	public function getContentType(): string
	{
		return $this->contentType;
	}


	public function setEscapingContext(?string $context, ?string $subContext = null): static
	{
		$this->context = $context;
		$this->subContext = $subContext;
		return $this;
	}


	public function getEscapingContext(): array
	{
		return [$this->contentType, $this->context, $this->subContext];
	}


	public function addBlock(Block $block, ?array $context = null): void
	{
		$block->context = implode('', $context ?? $this->getEscapingContext());
		$block->method = 'block' . ucfirst(trim(preg_replace('#\W+#', '_', $block->name->print($this)), '_'));
		$lower = strtolower($block->method);
		$used = $this->blocks + ['block' => 1];
		$counter = null;
		while (isset($used[$lower . $counter])) {
			$counter++;
		}

		$block->method .= $counter;
		$this->blocks[$lower . $counter] = $block;
	}


	// PHP helpers


	public function encodeString(string $str, string $quote = "'"): string
	{
		return $quote === "'"
			? "'" . addcslashes($str, "'\\") . "'"
			: '"' . addcslashes($str, "\n\r\t\f\v$\"\\") . '"';
	}


	/**
	 * Prints an infix operation while taking precedence into account.
	 */
	public function infixOp(Node $node, Node $leftNode, string $operatorString, Node $rightNode): string
	{
		[$precedence, $associativity] = $this->getPrecedence($node);
		return $this->prec($leftNode, $precedence, $associativity, -1)
			. $operatorString
			. $this->prec($rightNode, $precedence, $associativity, 1);
	}


	/**
	 * Prints a prefix operation while taking precedence into account.
	 */
	public function prefixOp(Node $node, string $operatorString, Node $expr): string
	{
		[$precedence, $associativity] = $this->getPrecedence($node);
		return $operatorString . $this->prec($expr, $precedence, $associativity, 1);
	}


	/**
	 * Prints a postfix operation while taking precedence into account.
	 */
	public function postfixOp(Node $node, Node $var, string $operatorString): string
	{
		[$precedence, $associativity] = $this->getPrecedence($node);
		return $this->prec($var, $precedence, $associativity, -1) . $operatorString;
	}


	/**
	 * Prints an expression node with the least amount of parentheses necessary to preserve the meaning.
	 */
	private function prec(Node $node, int $parentPrecedence, int $parentAssociativity, int $childPosition): string
	{
		$precedence = $this->getPrecedence($node);
		if ($precedence) {
			$childPrecedence = $precedence[0];
			if ($childPrecedence > $parentPrecedence
				|| ($parentPrecedence === $childPrecedence && $parentAssociativity !== $childPosition)
			) {
				return '(' . $node->print($this) . ')';
			}
		}

		return $node->print($this);
	}


	private function getPrecedence(Node $node): ?array
	{
		return $node instanceof Expression\BinaryOpNode
			? $this->binaryPrecedenceMap[$node->operator]
			: $this->exprPrecedenceMap[$node::class] ?? null;
	}


	/**
	 * Prints an array of nodes and implodes the printed values with $glue
	 */
	public function implode(array $nodes, string $glue = ', '): string
	{
		$pNodes = [];
		foreach ($nodes as $node) {
			if ($node === null) {
				$pNodes[] = '';
			} else {
				$pNodes[] = $node->print($this);
			}
		}

		return implode($glue, $pNodes);
	}


	public function objectProperty($node): string
	{
		if ($node instanceof ExpressionNode) {
			return '{' . $node->print($this) . '}';
		} else {
			return (string) $node;
		}
	}


	public function propertyAsValue($node): string
	{
		if ($node instanceof ExpressionNode) {
			return $node->print($this);
		} else {
			return $this->encodeString((string) $node);
		}
	}


	/**
	 * Wraps the LHS of a call in parentheses if needed.
	 */
	public function callExpr(Node $expr): string
	{
		return $expr instanceof Nodes\NameNode
			|| $expr instanceof Expression\VariableNode
			|| $expr instanceof Expression\ArrayAccessNode
			|| $expr instanceof Expression\FunctionCallNode
			|| $expr instanceof Expression\MethodCallNode
			|| $expr instanceof Expression\NullsafeMethodCallNode
			|| $expr instanceof Expression\StaticCallNode
			|| $expr instanceof Expression\ArrayNode
			? $expr->print($this)
			: '(' . $expr->print($this) . ')';
	}


	/**
	 * Wraps the LHS of a dereferencing operation in parentheses if needed.
	 */
	public function dereferenceExpr(Node $expr): string
	{
		return $expr instanceof Expression\VariableNode
			|| $expr instanceof Nodes\NameNode
			|| $expr instanceof Expression\ArrayAccessNode
			|| $expr instanceof Expression\PropertyFetchNode
			|| $expr instanceof Expression\NullsafePropertyFetchNode
			|| $expr instanceof Expression\StaticPropertyFetchNode
			|| $expr instanceof Expression\FunctionCallNode
			|| $expr instanceof Expression\MethodCallNode
			|| $expr instanceof Expression\NullsafeMethodCallNode
			|| $expr instanceof Expression\StaticCallNode
			|| $expr instanceof Expression\ArrayNode
			|| $expr instanceof Scalar\StringNode
			|| $expr instanceof Scalar\BooleanNode
			|| $expr instanceof Scalar\NullNode
			|| $expr instanceof Expression\ConstantFetchNode
			|| $expr instanceof Expression\ClassConstantFetchNode
			? $expr->print($this)
			: '(' . $expr->print($this) . ')';
	}
}
