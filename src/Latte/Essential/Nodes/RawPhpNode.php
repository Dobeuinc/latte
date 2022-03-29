<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Essential\Nodes;

use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;


/**
 * {php statement; statement; ...}
 */
class RawPhpNode extends StatementNode
{
	public string $code;


	public static function create(Tag $tag): static
	{
		$tag->expectArguments();
		$node = new static;
		$node->code = trim($tag->parser->stream->getText(advance: true));
		if (!preg_match('~[;}]$~', $node->code)) {
			$node->code .= ';';
		}
		return $node;
	}


	public function print(PrintContext $context): string
	{
		return $context->format(
			'%line; %raw ',
			$this->startLine,
			$this->code,
		);
	}
}
