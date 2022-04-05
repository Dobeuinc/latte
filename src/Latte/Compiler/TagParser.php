<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Compiler;

use Latte\CompileException;
use Latte\Compiler\Nodes\Php as Node;
use Latte\Compiler\Nodes\Php\Expression;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\NameNode;
use Latte\Compiler\Nodes\Php\Scalar;
use Latte\Strict;


/**
 * Parser for PHP-like expression language used in tags.
 * Based on works by Nikita Popov, Moriyoshi Koizumi and Masato Bito.
 */
final class TagParser extends TagParserData
{
	use Strict;

	private const
		SchemaExpression = 'e',
		SchemaArguments = 'a',
		SchemaFilters = 'm';

	private const TokensHidden = [Token::Php_Whitespace, Token::Php_Comment];

	private const SymbolNone = -1;

	/** readonly */
	public TokenStream $stream;


	public function __construct(array $tokens)
	{
		$this->stream = new TokenStream(new \ArrayIterator($tokens), self::TokensHidden);
	}


	public function parseExpression(): ExpressionNode
	{
		return $this->parse(self::SchemaExpression, recovery: true);
	}


	public function parseArguments(): Expression\ArrayNode
	{
		return $this->parse(self::SchemaArguments, recovery: true);
	}


	public function parseFilters(): ?Expression\FilterNode
	{
		return !$this->isEnd()
			? $this->parse(self::SchemaFilters)
			: null;
	}


	public function isEnd(): bool
	{
		return !$this->stream->current();
	}


	public function parseUnquotedStringOrExpression(): ExpressionNode
	{
		try {
			$start = $this->stream->getIndex();
			$res = $this->parseUnquotedString();
			$end = $this->stream->getIndex();

			try {
				$this->stream->seek($start);
				$resAlt = $this->parseExpression();
				if ($this->stream->getIndex() > $end) {
					return $resAlt;
				} else {
					$this->stream->seek($end);
				}
			} catch (CompileException) {
				$this->stream->seek($end);
			}
		} catch (CompileException) {
			$this->stream->seek($start);
			$res = $this->parseExpression();
		}

		return $res;
	}


	private function parseUnquotedString(): Scalar\StringNode|Scalar\EncapsedStringNode
	{
		$token = $this->stream->consume();
		if (!preg_match('~[a-zA-Z_:.@/]~A', $token->text)) {
			throw new CompileException('');
		}

		$string = new Scalar\EncapsedStringNode([], $token->line);
		do {
			if ($token->is(Token::Php_Variable)) {
				$string->parts[] = new Expression\VariableNode(substr($token->text, 1));
			} elseif (end($string->parts) instanceof Scalar\EncapsedStringPartNode) {
				end($string->parts)->value .= $token->text;
			} else {
				$string->parts[] = new Scalar\EncapsedStringPartNode($token->text);
			}

			$token = $this->stream->peek(0);
			if (
				!$token
				|| $token->is(Token::Php_Whitespace, Token::Php_ConstantEncapsedString, ',', "'", '"', '::', '|')
			) {
				break;
			}

			$token = $this->stream->consume();
		} while (true);

		return count($string->parts) === 1
			? new Scalar\StringNode($string->parts[0]->value, $string->startLine)
			: $string;
	}


	/**
	 * @param  string|string[]  $modifiers
	 * @return array{ExpressionNode, ?string}
	 */
	public function parseWithModifier(string|array $modifiers, $unquotedString = true): array
	{
		$modifiers = (array) $modifiers;
		$save = $this->stream->getIndex();
		try {
			$mod = $this->stream->tryConsume(...$modifiers);
			if (!$mod || (ctype_alnum($mod->text) && !$this->stream->peek(0)?->is(Token::Php_Whitespace))) {
				goto expression;
			}
			return [$unquotedString
				? $this->parseUnquotedStringOrExpression()
				: $this->parseExpression(), $mod->text, ];

		} catch (CompileException) {
			expression:
			$this->stream->seek($save);
			return [$unquotedString
				? $this->parseUnquotedStringOrExpression()
				: $this->parseExpression(), null, ];
		}
	}


	public function parseType(): ?string
	{
		$kind = [
			Token::Php_Identifier, Token::Php_Constant, Token::Php_Ellipsis, Token::Php_Array, Token::Php_Integer, Token::Php_NameFullyQualified, Token::Php_NameQualified,
			'(', ')', '<', '>', '[', ']', '|', '&', '{', '}', ':', ',', '=', '?',
		];
		$res = null;
		while ($token = $this->stream->tryConsume(...$kind)) {
			$res .= $token->text;
		}

		return $res;
	}


	/** @throws CompileException */
	private function parse(string $schema, bool $recovery = false)
	{
		$symbol = self::SymbolNone; // We start off with no lookahead-token
		$this->startTokenStack = []; // Keep stack of start token
		$token = null;
		$state = 0; // Start off in the initial state and keep a stack of previous states
		$stateStack = [$state];
		$this->semStack = []; // Semantic value stack (contains values of tokens and semantic action results)
		$stackPos = 0; // Current position in the stack(s)

		do {
			if (self::ActionBase[$state] === 0) {
				$rule = self::ActionDefault[$state];
			} else {
				if ($symbol === self::SymbolNone) {
					$recovery = $recovery
						? [$this->stream->getIndex(), $state, $stateStack, $stackPos, $this->semValue, $this->semStack, $this->startTokenStack]
						: null;


					if ($token) {
						$prevToken = $token;
						$token = $this->stream->tryConsume() ?? $this->createToken();
					} else {
						$token = $this->createToken($schema);
					}

					recovery:
					$symbol = self::TokenToSymbol[$token->type];
				}

				$idx = self::ActionBase[$state] + $symbol;
				if ((($idx >= 0 && $idx < count(self::Action) && self::ActionCheck[$idx] === $symbol)
					|| ($state < self::Yy2Tblstate
						&& ($idx = self::ActionBase[$state + self::NumNonLeafStates] + $symbol) >= 0
						&& $idx < count(self::Action) && self::ActionCheck[$idx] === $symbol))
					&& ($action = self::Action[$idx]) !== self::DefaultAction
				) {
					/*
					>= numNonLeafStates: shift and reduce
					> 0: shift
					= 0: accept
					< 0: reduce
					= -YYUNEXPECTED: error
					*/
					if ($action > 0) { // shift
						++$stackPos;
						$stateStack[$stackPos] = $state = $action;
						$this->semStack[$stackPos] = $token->text;
						$this->startTokenStack[$stackPos] = $token;
						$symbol = self::SymbolNone;
						if ($action < self::NumNonLeafStates) {
							continue;
						}

						$rule = $action - self::NumNonLeafStates; // shift-and-reduce
					} else {
						$rule = -$action;
					}
				} else {
					$rule = self::ActionDefault[$state];
				}
			}

			do {
				if ($rule === 0) { // accept
					return $this->semValue;

				} elseif ($rule !== self::UnexpectedTokenRule) { // reduce
					$this->reduce($rule, $stackPos);

					// Goto - shift nonterminal
					$ruleLength = self::RuleToLength[$rule];
					$stackPos -= $ruleLength;
					$nonTerminal = self::RuleToNonTerminal[$rule];
					$idx = self::GotoBase[$nonTerminal] + $stateStack[$stackPos];
					if ($idx >= 0 && $idx < count(self::Goto) && self::GotoCheck[$idx] === $nonTerminal) {
						$state = self::Goto[$idx];
					} else {
						$state = self::GotoDefault[$nonTerminal];
					}

					++$stackPos;
					$stateStack[$stackPos] = $state;
					$this->semStack[$stackPos] = $this->semValue;
					if ($ruleLength === 0) {
						$this->startTokenStack[$stackPos] = $token;
					}

				} else { // error
					if ($prevToken->is('echo', 'print', 'return', 'yield', 'throw', 'if', 'foreach', 'unset')) {
						throw new CompileException("Keyword '$prevToken->text' is forbidden in Latte", $prevToken->line, $prevToken->column);
					}

					$expected = $this->getExpectedTokens($state, $expectEof);
					if ($expectEof && $recovery) {
						[, $state, $stateStack, $stackPos, $this->semValue, $this->semStack, $this->startTokenStack] = $recovery;
						$this->stream->seek($recovery[0]);
						$token = $this->createToken();
						goto recovery;
					}

					throw new CompileException(
						'Unexpected ' . ($token->text ? "'$token->text'" : 'end') . ($expected ? ', expecting ' . implode(' or ', $expected) : ''),
						$token->line,
						$token->column,
					);
				}

				if ($state < self::NumNonLeafStates) {
					break;
				}

				$rule = $state - self::NumNonLeafStates; // shift-and-reduce
			} while (true);
		} while (true);
	}


	/**
	 * Get limited number of expected tokens in given state. If too many, an empty array is returned.
	 */
	private function getExpectedTokens(int $state, bool &$expectEof = null): array
	{
		$expected = [];
		$expectEof = false;
		$base = self::ActionBase[$state];
		foreach (self::SymbolToName as $symbol => $name) {
			$idx = $base + $symbol;
			if (($idx >= 0 && $idx < count(self::Action) && self::ActionCheck[$idx] === $symbol
					|| $state < self::Yy2Tblstate
					&& ($idx = self::ActionBase[$state + self::NumNonLeafStates] + $symbol) >= 0
					&& $idx < count(self::Action) && self::ActionCheck[$idx] === $symbol)
				&& self::Action[$idx] !== self::UnexpectedTokenRule
				&& self::Action[$idx] !== self::DefaultAction
				&& $symbol !== self::ErrorSymbol
			) {
				if ($symbol === 0) {
					$expectEof = true;
				} elseif (count($expected) === 5) {
					return [];
				}

				$expected[] = $name;
			}
		}

		return $expected;
	}


	protected function handleBuiltinTypes(NameNode $name): NameNode|Node\IdentifierNode
	{
		$builtinTypes = [
			'bool' => true, 'int' => true, 'float' => true, 'string' => true, 'iterable' => true, 'void' => true,
			'object' => true, 'null' => true, 'false' => true, 'mixed' => true, 'never' => true,
		];

		$lowerName = strtolower((string) $name);
		return $name->isUnqualified() && isset($builtinTypes[$lowerName])
			? new Node\IdentifierNode($lowerName, $name->startLine)
			: $name;
	}


	protected function parseOffset(string $str, ?int $line): Scalar\StringNode|Scalar\IntegerNode
	{
		if (!preg_match('/^(?:0|-?[1-9][0-9]*)$/', $str)) {
			return new Scalar\StringNode($str, $line);
		}

		$num = +$str;
		if (!is_int($num)) {
			return new Scalar\StringNode($str, $line);
		}

		return new Scalar\IntegerNode($num, Scalar\IntegerNode::KindDecimal, $line);
	}


	/** @param ExpressionNode[] $parts */
	protected function parseDocString(
		string $startToken,
		array $parts,
		string $endToken,
		int $line,
		int $endLine,
	): Scalar\StringNode|Scalar\EncapsedStringNode {
		$hereDoc = !str_contains($startToken, "'");
		preg_match('/\A[ \t]*/', $endToken, $matches);
		$indentation = $matches[0];
		if (str_contains($indentation, ' ') && str_contains($indentation, "\t")) {
			throw new CompileException('Invalid indentation - tabs and spaces cannot be mixed', $endLine);

		} elseif (!$parts) {
			return new Scalar\StringNode('', $line);

		} elseif (!$parts[0] instanceof Scalar\EncapsedStringPartNode) {
			// If there is no leading encapsed string part, pretend there is an empty one
			$this->stripIndentation('', $indentation, true, false, $parts[0]->startLine);
		}

		$newParts = [];
		foreach ($parts as $i => $part) {
			if ($part instanceof Scalar\EncapsedStringPartNode) {
				$isLast = $i === \count($parts) - 1;
				$part->value = $this->stripIndentation(
					$part->value,
					$indentation,
					$i === 0,
					$isLast,
					$part->startLine,
				);
				if ($isLast) {
					$part->value = preg_replace('~(\r\n|\n|\r)\z~', '', $part->value);
				}
				if ($hereDoc) {
					$part->value = PhpHelpers::decodeEscapeSequences($part->value, null);
				}
				if ($i === 0 && $isLast) {
					return new Scalar\StringNode($part->value, $line);
				}
				if ($part->value === '') {
					continue;
				}
			}
			$newParts[] = $part;
		}

		return new Scalar\EncapsedStringNode($newParts, $line);
	}


	private function stripIndentation(
		string $str,
		string $indentation,
		bool $atStart,
		bool $atEnd,
		int $line,
	): string {
		if ($indentation === '') {
			return $str;
		}
		$start = $atStart ? '(?:(?<=\n)|\A)' : '(?<=\n)';
		$end = $atEnd ? '(?:(?=[\r\n])|\z)' : '(?=[\r\n])';
		$regex = '/' . $start . '([ \t]*)(' . $end . ')?/D';
		return preg_replace_callback(
			$regex,
			function ($matches) use ($indentation, $line) {
				$indentLen = \strlen($indentation);
				$prefix = substr($matches[1], 0, $indentLen);
				if (str_contains($prefix, $indentation[0] === ' ' ? "\t" : ' ')) {
					throw new CompileException('Invalid indentation - tabs and spaces cannot be mixed', $line);
				} elseif (strlen($prefix) < $indentLen && !isset($matches[2])) {
					throw new CompileException(
						'Invalid body indentation level ' .
						'(expecting an indentation level of at least ' . $indentLen . ')',
						$line,
					);
				}
				return substr($matches[0], strlen($prefix));
			},
			$str,
		);
	}


	private function createToken(string $text = null): Token
	{
		$prev = $this->stream->peek(-1);
		return new Token(ord($text ?? "\0"), $text ?? '', $prev?->line, $prev?->column + strlen($prev?->text ?? ''));
	}
}
