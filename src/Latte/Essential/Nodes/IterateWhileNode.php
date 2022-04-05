<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Essential\Nodes;

use Latte\CompileException;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\LegacyExprNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;


/**
 * {iterateWhile $cond}
 */
class IterateWhileNode extends StatementNode
{
	public LegacyExprNode $condition;
	public AreaNode $content;
	public string $args;
	public bool $postTest;


	/** @return \Generator<int, ?array, array{AreaNode, ?Tag}, static> */
	public static function create(Tag $tag): \Generator
	{
		$foreach = $tag->closest(['foreach']);
		if (!$foreach) {
			throw new CompileException("Tag {{$tag->name}} must be inside {foreach} ... {/foreach}.", $tag->startLine);
		}

		$node = new static;
		$node->postTest = $tag->args === '';
		if (!$node->postTest) {
			$node->condition = $tag->getArgs();
		}

		$node->args = preg_replace('#^.+\s+as\s+(?:(.+)=>)?(.+)$#i', '$1, $2', $foreach->data->iterateWhile);
		[$node->content, $nextTag] = yield;
		if ($node->postTest) {
			$nextTag->expectArguments();
			$node->condition = $nextTag->getArgs();
		}

		return $node;
	}


	public function print(PrintContext $context): string
	{
		$stmt = $context->format(
			<<<'XX'
				if (!$iterator->hasNext() || !(%args)) {
					break;
				}
				$iterator->next();
				[%raw] = [$iterator->key(), $iterator->current()];
				XX,
			$this->condition,
			$this->args,
		);

		return $context->format(
			<<<'XX'
				do %line {
					%raw
					%raw
				} while (true);

				XX,
			$this->startLine,
			...($this->postTest ? [$this->content, $stmt] : [$stmt, $this->content]),
		);
	}


	public function &getIterator(): \Generator
	{
		yield $this->condition;
		yield $this->content;
	}
}
