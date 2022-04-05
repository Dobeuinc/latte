<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Essential\Nodes;

use Latte\Compiler\Block;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\Php\Expression\AssignNode;
use Latte\Compiler\Nodes\Php\Expression\FilterNode;
use Latte\Compiler\Nodes\Php\Expression\VariableNode;
use Latte\Compiler\Nodes\Php\Scalar;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;
use Latte\Compiler\TemplateParser;
use Latte\Context;
use Latte\Runtime\Template;


/**
 * {block [local] [name]}
 */
class BlockNode extends StatementNode
{
	public ?Block $block = null;
	public ?FilterNode $filter;
	public AreaNode $content;


	/** @return \Generator<int, ?array, array{AreaNode, ?Tag}, static|AreaNode> */
	public static function create(Tag $tag, TemplateParser $parser): \Generator
	{
		$tag->outputMode = $tag::OutputRemoveIndentation;
		$stream = $tag->parser->stream;
		$node = new static;

		if ($stream->current() && !$stream->is('|')) {
			[$name, $mod] = $tag->parser->parseWithModifier(['local', '#']);
			$layer = $mod === 'local' ? Template::LayerLocal : $parser->blockLayer;
			$node->block = new Block($name, $layer, $tag);

			if (!$node->block->isDynamic()) {
				$parser->checkBlockIsUnique($node->block);
				$tag->data->block = $node->block; // for {include}
			}
		}

		$node->filter = $tag->parser->parseFilters();
		[$node->content, $endTag] = yield;

		if ($node->block) {
			if ($endTag && $name instanceof Scalar\StringNode) {
				$endTag->parser->stream->tryConsume($name->value);
			}
		} else {
			if (!$node->filter) {
				return $node->content;
			}

			$node->filter = FilterNode::escapeFilter($node->filter);
		}

		return $node;
	}


	public function print(PrintContext $context): string
	{
		if (!$this->block) {
			return $this->printFilter($context);

		} elseif ($this->block->isDynamic()) {
			return $this->printDynamic($context);
		}

		return $this->printStatic($context);
	}


	private function printFilter(PrintContext $context): string
	{
		return $context->format(
			<<<'XX'
				ob_start(fn() => '') %line;
				try {
					%raw
				} finally {
					$ʟ_fi = new LR\FilterInfo(%dump);
					echo %modifyContent(ob_get_clean());
				}

				XX,
			$this->filter,
			$this->startLine,
			$this->content,
			implode('', $context->getEscapingContext()),
		);
	}


	private function printStatic(PrintContext $context): string
	{
		[$escapingContext, $filter] = $this->adjustContext($context->getEscapingContext());
		$context->addBlock($this->block, $escapingContext);
		$this->block->content = $this->content->print($context); // must be compiled after is added

		return $context->format(
			'$this->renderBlock(%raw, get_defined_vars()'
			. ($filter
				? $context->format(
					', function ($s, $type) { $ʟ_fi = new LR\FilterInfo($type); return %modifyContent($s); }',
					$filter,
				)
				: '')
			. ') %line;',
			$this->block->name,
			$this->startLine,
		);
	}


	private function printDynamic(PrintContext $context): string
	{
		[$escapingContext, $filter] = $this->adjustContext($context->getEscapingContext());
		$context->addBlock($this->block);
		$this->block->content = $this->content->print($context); // must be compiled after is added

		return $context->format(
			'$this->addBlock(%raw, %dump, [[$this, %dump]], %dump);
			$this->renderBlock($ʟ_nm, get_defined_vars()'
			. ($filter
				? $context->format(
					', function ($s, $type) { $ʟ_fi = new LR\FilterInfo($type); return %modifyContent($s); }',
					$filter,
				)
				: '')
			. ');',
			new AssignNode(new VariableNode('ʟ_nm'), $this->block->name),
			implode('', $escapingContext),
			$this->block->method,
			$this->block->layer,
		);
	}


	private function adjustContext(array $context): array
	{
		if (str_starts_with((string) $context[1], Context::HtmlAttribute)) {
			$context[1] = null;
			$filter = FilterNode::escapeFilter($this->filter);
		} elseif ($this->filter) {
			$filter = FilterNode::escapeFilter($this->filter);
		}
		return [$context, $filter ?? null];
	}


	public function &getIterator(): \Generator
	{
		if ($this->block) {
			yield $this->block->name;
		}
		if ($this->filter) {
			yield $this->filter;
		}
		yield $this->content;
	}
}
