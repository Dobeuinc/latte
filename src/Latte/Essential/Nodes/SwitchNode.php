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
use Latte\Compiler\Nodes\TextNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;


/**
 * {switch} ... {case} ... {default}
 */
class SwitchNode extends StatementNode
{
	public ?LegacyExprNode $expression;
	public array $cases = [];


	/** @return \Generator<int, ?array, array{AreaNode, ?Tag}, static> */
	public static function create(Tag $tag): \Generator
	{
		$node = new static;
		$node->expression = $tag->getArgs();

		[$content, $nextTag] = yield ['case', 'default'];
		foreach ($content->children as $child) {
			if (!$child instanceof TextNode || !$child->isWhitespace()) {
				throw new CompileException('No content is allowed between {switch} and {case}', $child->startLine);
			}
		}

		$default = 0;
		while (true) {
			if ($nextTag?->name === 'case') {
				$nextTag->expectArguments();
				$tmp = $nextTag;
				[$content, $nextTag] = yield ['case', 'default'];
				$node->cases[] = [$tmp->getArgs(), $content, $tmp->startLine];

			} elseif ($nextTag?->name === 'default') {
				if ($default++) {
					throw new CompileException('Tag {switch} may only contain one {default} clause.', $nextTag->startLine);
				}
				$nextTag->expectArguments(false);
				$tmp = $nextTag;
				[$content, $nextTag] = yield ['case', 'default'];
				$node->cases[] = [null, $content, $tmp->startLine];

			} else {
				return $node;
			}
		}
	}


	public function print(PrintContext $context): string
	{
		$res = $context->format(
			'$ʟ_switch = (%raw) %line;',
			$this->expression,
			$this->startLine,
		);
		$first = true;
		$default = null;
		foreach ($this->cases as [$condition, $stmt, $line]) {
			if (!$condition) {
				$default = $stmt->print($context);
				continue;
			} elseif (!$first) {
				$res .= 'else';
			}

			$first = false;
			$res .= $context->format(
				'if (in_array($ʟ_switch, %array, true)) %line { %raw } ',
				$condition,
				$line,
				$stmt,
			);
		}

		if ($default) {
			$res .= $first ? $default : 'else { ' . $default . ' } ';
		}
		return $res;
	}


	public function &getIterator(): \Generator
	{
		yield $this->expression;
		foreach ($this->cases as [&$case, &$stmt]) {
			if ($case) {
				yield $case;
			}
			yield $stmt;
		}
	}
}
