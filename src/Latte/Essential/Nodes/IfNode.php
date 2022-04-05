<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Essential\Nodes;

use Latte\CompileException;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\Php\Expression;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\Scalar\StringNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;
use Latte\Compiler\TagParser;
use Latte\Compiler\TemplateParser;


/**
 * {if $cond} & {elseif $cond} & {else}
 * {if} & {/if $cond}
 * {ifset $var} & {elseifset $var}
 * {ifset block} & {elseifset block}
 */
class IfNode extends StatementNode
{
	public ExpressionNode $condition;
	public AreaNode $then;
	public ?AreaNode $else = null;
	public ?int $elseLine = null;
	public bool $capture = false;
	public bool $ifset = false;


	/** @return \Generator<int, ?array, array{AreaNode, ?Tag}, static> */
	public static function create(Tag $tag, TemplateParser $parser): \Generator
	{
		$node = new static;
		$node->ifset = in_array($tag->name, ['ifset', 'elseifset'], true);
		$node->capture = $tag->name === 'if' && $tag->parser->isEnd();
		$node->startLine = $tag->startLine;
		if (!$node->capture) {
			$node->condition = $node->ifset
				? self::buildCondition($tag->parser)
				: $tag->parser->parseExpression();
		}

		[$node->then, $nextTag] = yield $node->capture ? ['else'] : ['else', 'elseif', 'elseifset'];

		if ($nextTag?->name === 'else') {
			if ($nextTag->parser->stream->is('if')) {
				throw new CompileException('Arguments are not allowed in {else}, did you mean {elseif}?', $nextTag->startLine);
			}
			$node->elseLine = $nextTag->startLine;
			[$node->else, $nextTag] = yield;

		} elseif ($nextTag?->name === 'elseif' || $nextTag?->name === 'elseifset') {
			if ($node->capture) {
				throw new CompileException('Tag ' . $nextTag->getNotation() . ' is unexpected here.', $nextTag->startLine);
			}
			$node->else = yield from self::create($nextTag, $parser);
		}

		if ($node->capture) {
			$node->condition = $nextTag->parser->parseExpression();
		}

		return $node;
	}


	private static function buildCondition(TagParser $parser): ExpressionNode
	{
		$list = [];
		do {
			[$name, $block] = $parser->parseWithModifier(['block', '#'], unquotedString: false);
			$list[] = $block || $name instanceof StringNode
				? Expression\MethodCallNode::from(new Expression\VariableNode('this'), 'hasBlock', [$name])
				: new Expression\IssetNode([ExpressionNode::fromValue($name)]);
		} while ($parser->stream->tryConsume(','));

		return Expression\BinaryOpNode::nest('&&', ...$list);
	}


	public function print(PrintContext $context): string
	{
		return $this->capture
			? $this->printCapturing($context)
			: $this->printCommon($context);
	}


	private function printCommon(PrintContext $context): string
	{
		if ($this->else) {
			return $context->format(
				($this->else instanceof self
					? "if (%raw) %line { %raw } else%raw\n"
					: "if (%raw) %line { %raw } else %4.line { %3.raw }\n"),
				$this->condition,
				$this->startLine,
				$this->then,
				$this->else,
				$this->elseLine,
			);
		}
		return $context->format(
			"if (%raw) %line { %raw }\n",
			$this->condition,
			$this->startLine,
			$this->then,
		);
	}


	private function printCapturing(PrintContext $context): string
	{
		if ($this->else) {
			return $context->format(
				<<<'XX'
					ob_start(fn() => '') %line;
					try {
						%raw
						ob_start(fn() => '') %line;
						try {
							%raw
						} finally {
							$ʟ_ifB = ob_get_clean();
						}
					} finally {
						$ʟ_ifA = ob_get_clean();
					}
					echo (%raw) ? $ʟ_ifA : $ʟ_ifB %0.line;


					XX,
				$this->startLine,
				$this->then,
				$this->elseLine,
				$this->else,
				$this->condition,
			);
		}

		return $context->format(
			<<<'XX'
				ob_start(fn() => '') %line;
				try {
					%raw
				} finally {
					$ʟ_ifA = ob_get_clean();
				}
				if (%raw) %0.line { echo $ʟ_ifA; }

				XX,
			$this->startLine,
			$this->then,
			$this->condition,
		);
	}


	public function &getIterator(): \Generator
	{
		yield $this->condition;
		yield $this->then;
		if ($this->else) {
			yield $this->else;
		}
	}
}
