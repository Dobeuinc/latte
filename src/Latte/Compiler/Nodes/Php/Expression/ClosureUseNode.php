<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Compiler\Nodes\Php\Expression;

use Latte\Compiler\Nodes\Php;
use Latte\Compiler\PrintContext;


class ClosureUseNode extends Php\ExpressionNode
{
	public function __construct(
		public VariableNode $var,
		public bool $byRef = false,
		public ?int $startLine = null,
	) {
	}


	public function print(PrintContext $context): string
	{
		return ($this->byRef ? '&' : '') . $this->var->print($context);
	}


	public function &getIterator(): \Generator
	{
		yield $this->var;
	}
}
