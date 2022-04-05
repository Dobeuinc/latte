<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Essential\Nodes;

use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\Php\Expression\FilterNode;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PhpHelpers;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;


/**
 * {include [file] "file" [with blocks] [,] [params]}
 */
class IncludeFileNode extends StatementNode
{
	public ExpressionNode $file;
	public ArrayNode $args;
	public ?FilterNode $filter = null;
	public string $mode;


	public static function create(Tag $tag): static
	{
		$tag->outputMode = $tag::OutputRemoveIndentation;

		$tag->expectArguments();
		$node = new static;
		[$node->file] = $tag->parser->parseWithModifier(['file']);
		$node->mode = 'include';

		$stream = $tag->parser->stream;
		if ($stream->tryConsume('with')) {
			$stream->consume('blocks');
			$node->mode = 'includeblock';
		}

		$stream->tryConsume(',');
		$node->args = $tag->parser->parseArguments();
		$node->filter = $tag->parser->parseFilters();
		return $node;
	}


	public function print(PrintContext $context): string
	{
		$filter = $noEscape = (string) $this->filter?->name === 'noescape'
			? $this->filter->inner
			: ($this->filter ? FilterNode::escapeFilter($this->filter) : null);

		return $context->format(
			'$this->createTemplate(%raw, %raw? + $this->params, %dump)->renderToContentType(%raw) %line;',
			$this->file,
			$this->args,
			$this->mode,
			$filter
				? $context->format(
					'function ($s, $type) { $ʟ_fi = new LR\FilterInfo($type); return %modifyContent($s); }',
					$filter,
				)
				: PhpHelpers::dump($noEscape ? null : implode('', $context->getEscapingContext())),
			$this->startLine,
		);
	}


	public function &getIterator(): \Generator
	{
		yield $this->file;
		yield $this->args;
		if ($this->filter) {
			yield $this->filter;
		}
	}
}
