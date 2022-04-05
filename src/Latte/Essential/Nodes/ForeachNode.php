<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Essential\Nodes;

use Latte\CompileException;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\NopNode;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;
use Latte\Compiler\TagParser;


/**
 * {foreach $expr as $key => $value} & {else}
 */
class ForeachNode extends StatementNode
{
	public ExpressionNode $expression;
	public ?ExpressionNode $key = null;
	public bool $byRef = false;
	public ExpressionNode $value;
	public AreaNode $content;
	public ?AreaNode $else = null;
	public ?bool $iterator = null;
	public bool $checkArgs = true;


	/** @return \Generator<int, ?array, array{AreaNode, ?Tag}, static|NopNode> */
	public static function create(Tag $tag): \Generator
	{
		$tag->expectArguments();
		$node = new static;
		self::parseArguments($tag->parser, $node);
		$tag->data->iterateWhile = [$node->key, $node->value];

		$filter = $tag->parser->parseFilters();
		while ($filter) {
			match ((string) $filter->name) {
				'nocheck' => $node->checkArgs = false,
				'noiterator' => $node->iterator = false,
				default => throw new CompileException('Only modifiers |noiterator and |nocheck are allowed here.', $tag->startLine),
			};
			$filter = $filter->inner;
		}

		if ($tag->void) {
			$node->content = new NopNode;
			return $node;
		}

		[$node->content, $nextTag] = yield ['else'];
		if ($nextTag?->name === 'else') {
			[$node->else] = yield;
		}

		return $node;
	}


	private static function parseArguments(TagParser $parser, self $node): void
	{
		$stream = $parser->stream;
		$node->expression = $parser->parseExpression();
		$stream->consume('as');
		if (!$stream->is('&')) {
			$node->value = $parser->parseExpression();
			if (!$stream->tryConsume('=>')) {
				return;
			}
			$node->key = $node->value;
		}

		$node->byRef = (bool) $stream->tryConsume('&');
		$node->value = $parser->parseExpression();
	}


	public function print(PrintContext $context): string
	{
		$content = $this->content->print($context);
		$iterator = $this->else || ($this->iterator ?? preg_match('#\$iterator\W|\Wget_defined_vars\W#', $content));
		$content .= '$iterations++;';

		if ($this->else) {
			$content .= $context->format(
				'} if ($iterator->isEmpty()) %line { ',
				$this->else->startLine,
			) . $this->else->print($context);
		}

		if ($iterator) {
			return $context->format(
				<<<'XX'
					$iterations = 0;
					foreach ($iterator = $ʟ_it = new Latte\Essential\CachingIterator(%raw, $ʟ_it ?? null) as %raw) %line {
						%raw
					}
					$iterator = $ʟ_it = $ʟ_it->getParent();


					XX,
				$this->expression,
				$this->printArgs($context),
				$this->startLine,
				$content,
			);

		} else {
			return $context->format(
				<<<'XX'
					$iterations = 0;
					foreach (%raw as %raw) %line {
						%raw
					}


					XX,
				$this->expression,
				$this->printArgs($context),
				$this->startLine,
				$content,
			);
		}
	}


	private function printArgs(PrintContext $context): string
	{
		return ($this->key ? $this->key->print($context) . ' => ' : '')
			. ($this->byRef ? '&' : '')
			. $this->value->print($context);
	}


	public function &getIterator(): \Generator
	{
		yield $this->expression;
		if ($this->key) {
			yield $this->key;
		}
		yield $this->value;
		yield $this->content;
		if ($this->else) {
			yield $this->else;
		}
	}
}
