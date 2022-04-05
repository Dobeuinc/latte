<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Compiler;

use Latte\CompileException;
use Latte\Strict;


/**
 * TokenStream loads tokens from $source iterator on-demand, and places them in a buffer to provide access
 * to any previous token by index. It also filters hidden tokens.
 */
final class TokenStream
{
	use Strict;

	/** @var Token[] */
	private array $tokens = [];
	private int $index = 0;
	private bool $end = false;
	private \Iterator $source;
	private array $hidden;


	public function __construct(\Iterator $source, array $hidden = [])
	{
		$this->source = $source;
		$this->hidden = array_flip($hidden);
	}


	/**
	 * Gets the token at current position or null on end.
	 */
	public function current(): ?Token
	{
		do {
			$token = $this->peek(0);
			if (!isset($this->hidden[$token?->type])) {
				return $token;
			}
			$this->index++;
		} while (true);
	}


	/**
	 * Tells whether the token at current position is of given kind.
	 */
	public function is(int|string ...$kind): bool
	{
		return $this->current()?->is(...$kind) ?? false;
	}


	/**
	 * Gets the token at $offset from the current position.
	 * This function does not take into account $hidden tokens.
	 */
	public function peek(int $offset): ?Token
	{
		$pos = $this->index + $offset;
		while (!$this->end && $pos >= 0 && !isset($this->tokens[$pos])) {
			if ($this->tokens) {
				$this->source->next();
			}

			if (!$this->source->valid()) {
				$this->end = true;
				break;
			}

			$this->tokens[] = $this->source->current();
		}

		return $this->tokens[$pos] ?? null;
	}


	/**
	 * Consumes the current token (if is of given kind) or throws exception on end.
	 * @throws CompileException
	 */
	public function consume(int|string ...$kind): Token
	{
		$token = $this->current();
		if (!$token || ($kind && !$token->is(...$kind))) {
			$kind = array_map(fn($item) => is_string($item) ? "'$item'" : Token::NAMES[$item], $kind);
			$this->throwUnexpectedException($kind);
		}
		$this->index++;
		return $token;
	}


	/**
	 * Consumes the current token of given kind or returns null.
	 */
	public function tryConsume(int|string ...$kind): ?Token
	{
		if (($token = $this->current())
			&& (!$kind || $token->is(...$kind))
		) {
			$this->index++;
			return $token;
		}
		return null;
	}


	/**
	 * Sets the input cursor to the position.
	 */
	public function seek(int $index): void
	{
		if ($index > count($this->tokens) || $index < 0) {
			throw new \InvalidArgumentException('The position is out of range.');
		}
		$this->index = $index;
	}


	/**
	 * Returns the cursor position.
	 */
	public function getIndex(): int
	{
		return $this->index;
	}


	/**
	 * Returns the text of all tokens in the stream.
	 */
	public function getText(int $start = 0, int $end = PHP_INT_MAX, bool $advance = false): string
	{
		$text = '';
		while ($start++ <= $end && ($token = $this->peek($start - $this->index - 1))) {
			$text .= $token->text;
		}

		if ($advance) {
			$this->index += $start - 1;
		}

		return $text;
	}


	/**
	 * @throws CompileException
	 * @return never
	 */
	public function throwUnexpectedException(array $expected = [], $addendum = ''): void
	{
		$s = null;
		$i = 0;
		do {
			if (!($token = $this->peek($i++))) {
				break;
			}
			$s .= $token->text;
			if (strlen($s) > 5) {
				break;
			}
		} while (true);

		$expected = array_map(fn($item) => is_int($item) ? Token::NAMES[$item] : $item, $expected);
		$last = $this->current() ?? $this->peek(-1) ?? new Token(0, '');

		throw new CompileException(
			'Unexpected '
			. ($s === null
				? 'end'
				: "'" . trim($s) . "'")
			. ($expected && count($expected) < 5
				? ', expecting ' . implode(', ', $expected)
				: '')
			. $addendum,
			$last->line,
			$last->column,
		);
	}
}
