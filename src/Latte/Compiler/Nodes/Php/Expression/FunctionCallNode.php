<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Compiler\Nodes\Php\Expression;

use Latte\Compiler\Nodes\Php;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\NameNode;
use Latte\Compiler\PrintContext;


class FunctionCallNode extends CallLikeNode
{
	public function __construct(
		public Php\NameNode|ExpressionNode $name,
		/** @var array<Php\ArgumentNode|Php\VariadicPlaceholderNode> */
		public array $args = [],
		public ?int $startLine = null,
	) {
	}


	public static function from(string|NameNode|ExpressionNode $name, array $args = []): static
	{
		return new static(
			is_string($name) ? NameNode::fromString($name) : $name,
			self::argumentsFromValues($args),
		);
	}


	public function print(PrintContext $context): string
	{
		return $context->callExpr($this->name)
			. '(' . $context->implode($this->args) . ')';
	}


	public function &getIterator(): \Generator
	{
		yield $this->name;
		foreach ($this->args as &$item) {
			yield $item;
		}
	}
}
