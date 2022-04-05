<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Compiler;

use Latte;
use Latte\CompileException;
use Latte\Compiler\Nodes\LegacyExprNode;
use Latte\Context;
use Latte\Helpers;
use Latte\Policy;
use Latte\SecurityViolationException;


/**
 * PHP code generator helpers.
 */
class PhpWriter
{
	use Latte\Strict;

	private ?MacroTokens $tokens;
	private ?PrintContext $printContext;

	/** @var array{string, mixed}|null */
	private ?array $context = null;
	private ?Policy $policy = null;

	/** @var string[] */
	private array $functions = [];


	public static function using(PrintContext $context): self
	{
		$me = new self(null);
		$me->printContext = $context;
		$me->context = $context->getEscapingContext();
		$me->policy = $context->policy;
		$names = array_keys($context->functions);
		$me->functions = array_combine(array_map('strtolower', $names), $names);
		return $me;
	}


	/**
	 * @param  array{string, mixed}|null  $context
	 */
	public function __construct(?MacroTokens $tokens, ?array $context = null)
	{
		$this->tokens = $tokens;
		$this->context = $context;
	}


	/**
	 * Expands %word, %array, %args, %line, %dump, %raw and %modify() in code.
	 */
	public function write(string $mask, mixed ...$args): string
	{
		if (str_contains($mask, '%modify')) {
			$modifier = array_shift($args);
			$mask = preg_replace_callback(
				'#%modify(Content)?(\(([^()]*+|(?2))+\))#',
				fn($m) => $this->formatModifiers($modifier, substr($m[2], 1, -1), (bool) $m[1]),
				$mask,
			);
		}

		return preg_replace_callback(
			'#([,+]?\s*)?%(\d+\.|)(word|dump|raw|array|args|line)(\?)?(\s*\+\s*)?()#',
			function ($m) use (&$args) {
				[, $l, $source, $format, $cond, $r] = $m;

				switch ($source) {
					case '':
						$arg = current($args);
						next($args);
						break;
					default:
						$arg = $args[(int) $source];
				}

				switch ($format) {
					case 'word':
						if ($arg instanceof LegacyExprNode) {
							$arg = $arg->text;
						}
						$code = $this->formatWord($arg); break;
					case 'args':
						if ($arg instanceof LegacyExprNode) {
							$arg = new MacroTokens($arg->text);
						}
						$code = $this->formatArgs($arg); break;
					case 'array':
						if ($arg instanceof LegacyExprNode) {
							$arg = new MacroTokens($arg->text);
						}
						$code = $this->formatArray($arg);
						$code = $cond && $code === '[]' ? '' : $code; break;
					case 'dump':
						$code = PhpHelpers::dump($arg); break;
					case 'raw':
						$code = $arg instanceof Node ? $arg->print($this->printContext) : $arg;
						break;
					case 'line':
						$l = trim($l);
						$line = (int) $arg;
						$code = $line ? " /* line $line */" : '';
						break;
				}

				if ($cond && $code === '') {
					return $r ? $l : $r;
				} else {
					return $l . $code . $r;
				}
			},
			$mask,
		);
	}


	/**
	 * Formats modifiers calling.
	 */
	public function formatModifiers(string $modifier, string $var, bool $isContent = false): string
	{
		static $uniq;
		$modifier = $this->completeModifier($modifier);
		$uniq ??= '$' . bin2hex(random_bytes(5));
		$tokens = new MacroTokens(ltrim($modifier, '|'));
		$tokens = $this->preprocess($tokens);
		$tokens = $this->modifierPass($tokens, $uniq, $isContent);
		$tokens = $this->quotingPass($tokens);
		$this->validateKeywords($tokens);
		return str_replace($uniq, $var, $tokens->joinAll());
	}


	/**
	 * Formats macro arguments to PHP code. (It advances tokenizer to the end as a side effect.)
	 */
	public function formatArgs(?MacroTokens $tokens = null): string
	{
		$tokens = $this->preprocess($tokens);
		$tokens = $this->quotingPass($tokens);
		$this->validateKeywords($tokens);
		return $tokens->joinAll();
	}


	/**
	 * Formats macro arguments to PHP array. (It advances tokenizer to the end as a side effect.)
	 */
	public function formatArray(?MacroTokens $tokens = null): string
	{
		$tokens = $this->preprocess($tokens);
		$tokens = $this->namedArgumentsPass($tokens);
		$tokens = $this->expandCastPass($tokens);
		$tokens = $this->quotingPass($tokens);
		$this->validateKeywords($tokens);
		return $tokens->joinAll();
	}


	/**
	 * Formats parameter to PHP string.
	 */
	public function formatWord(string $s): string
	{
		if (is_numeric($s)
			|| preg_match('#^[$([]|[\'"\ ]|^(true|TRUE)$|^(false|FALSE)$|^(null|NULL)$|^[\w\\\\]{3,}::[A-Z0-9_]{2,}$#D', $s)
		) {
			$s = preg_match('#\s#', $s) ? "($s)" : $s;
			return $this->formatArgs(new MacroTokens($s));
		}

		return '"' . $s . '"';
	}


	/**
	 * Preprocessor for tokens. (It advances tokenizer to the end as a side effect.)
	 */
	public function preprocess(?MacroTokens $tokens = null): MacroTokens
	{
		$tokens ??= $this->tokens;
		$this->validateTokens($tokens);
		$tokens = $this->removeCommentsPass($tokens);
		$tokens = $this->optionalChainingPass($tokens);
		$tokens = $this->shortTernaryPass($tokens);
		$tokens = $this->inOperatorPass($tokens);
		$tokens = $this->sandboxPass($tokens);
		$tokens = $this->replaceFunctionsPass($tokens);
		$tokens = $this->inlineModifierPass($tokens);
		$tokens = $this->modernArraySyntax($tokens);
		return $tokens;
	}


	/** @throws CompileException */
	public function validateTokens(MacroTokens $tokens): void
	{
		$brackets = [];
		$pos = $tokens->position;
		while ($tokens->nextToken()) {
			$tokenValue = $tokens->currentValue();
			if ($tokens->isCurrent('?>') || $tokens->isCurrent('#')) {
				throw new CompileException("Forbidden $tokenValue inside tag");

			} elseif ($tokens->isCurrent('/') && $tokens->isNext('/')) {
				throw new CompileException('Forbidden // inside tag');

			} elseif ($tokens->isCurrent('(', '[', '{')) {
				static $counterpart = ['(' => ')', '[' => ']', '{' => '}'];
				$brackets[] = $counterpart[$tokenValue];

			} elseif ($tokens->isCurrent(')', ']', '}') && $tokenValue !== array_pop($brackets)) {
				throw new CompileException('Unexpected ' . $tokenValue);

			} elseif ($tokens->isCurrent('`')) {
				throw new CompileException('Backtick operator is forbidden in Latte.');

			} elseif (
				$this->policy
				&& $tokens->isCurrent($tokens::T_STRING)
				&& $tokenValue[0] === '"'
				&& (str_contains($tokenValue, '{$') || str_contains($tokenValue, '${'))
			) {
				throw new CompileException('Forbidden complex expressions in strings.');

			} elseif (
				str_starts_with($tokenValue, '$ʟ_')
				|| ($this->policy && $tokens->isCurrent('$this'))
			) {
				throw new CompileException("Forbidden variable {$tokenValue}.");
			}
		}

		if ($brackets) {
			throw new CompileException('Missing ' . array_pop($brackets));
		}

		$tokens->position = $pos;
	}


	/** @throws CompileException */
	public function validateKeywords(MacroTokens $tokens): void
	{
		$pos = $tokens->position;
		while ($tokens->nextToken()) {
			if (
				!$tokens->isPrev('::', '->', '?->', '??->')
				&& (
					$tokens->isCurrent('__halt_compiler', 'declare', 'die', 'eval', 'exit', 'include', 'include_once', 'require', 'require_once')
					|| ($this->policy && $tokens->isCurrent(
						...['break', 'case', 'catch', 'continue', 'do', 'echo', 'else', 'elseif', 'endfor',
							'endforeach', 'endswitch', 'endwhile', 'finally', 'for', 'foreach', 'if', 'new', 'print', 'switch', 'throw', 'try', 'while', ],
					))
					|| (($this->policy || !$tokens->depth) && $tokens->isCurrent('return', 'yield'))
					|| (!$tokens->isNext('(') && $tokens->isCurrent('function', 'use'))
					|| ($tokens->isCurrent(
						...['abstract', 'class', 'const', 'enddeclare', 'extends', 'final', 'global', 'goto', 'implements',
							'insteadof', 'interface', 'namespace', 'private', 'protected', 'public', 'static', 'trait', 'var', ],
					))
				)
			) {
				throw new CompileException("Forbidden keyword '{$tokens->currentValue()}' inside tag.");
			}
		}

		$tokens->position = $pos;
	}


	/**
	 * Removes PHP comments.
	 */
	public function removeCommentsPass(MacroTokens $tokens): MacroTokens
	{
		$res = new MacroTokens;
		while ($tokens->nextToken()) {
			$res->append($tokens->isCurrent($tokens::T_COMMENT) ? ' ' : $tokens->currentToken());
		}

		return $res;
	}


	/**
	 * Replace global functions with custom ones.
	 */
	public function replaceFunctionsPass(MacroTokens $tokens): MacroTokens
	{
		$res = new MacroTokens;
		while ($tokens->nextToken()) {
			$name = $tokens->currentValue();
			if (
				$tokens->isCurrent($tokens::T_SYMBOL)
				&& ($orig = $this->functions[strtolower($name)] ?? null)
				&& $tokens->isNext('(')
				&& !$tokens->isPrev('::', '->', '?->', '??->', '\\')
			) {
				if ($name !== $orig) {
					trigger_error("Case mismatch on function name '$name', correct name is '$orig'.", E_USER_WARNING);
				}

				$res->append('($this->global->fn->' . $orig . ')');
			} else {
				$res->append($tokens->currentToken());
			}
		}

		return $res;
	}


	/**
	 * Simplified ternary expressions without third part.
	 */
	public function shortTernaryPass(MacroTokens $tokens): MacroTokens
	{
		$res = new MacroTokens;
		$inTernary = [];
		while ($tokens->nextToken()) {
			if (
				$tokens->isCurrent('?')
				&& $tokens->isNext(...$tokens::SIGNIFICANT)
				&& !$tokens->isNext(',', ')', ']', '|', '[')
			) {
				$inTernary[] = $tokens->depth;

			} elseif ($tokens->isCurrent(':')) {
				array_pop($inTernary);

			} elseif (
				$tokens->isCurrent(',', ')', ']', '|')
				&& end($inTernary) === $tokens->depth + $tokens->isCurrent(')', ']')
			) {
				$res->append(' : null');
				array_pop($inTernary);
			}

			$res->append($tokens->currentToken());
		}

		if ($inTernary) {
			$res->append(' : null');
		}

		return $res;
	}


	/**
	 * Nullsafe operator $var?->prop?->elem[1]?->call()?->item
	 * Null-coalescing-safe operator $var??->prop??->elem[1]??->call()??->item
	 */
	public function optionalChainingPass(MacroTokens $tokens): MacroTokens
	{
		$startDepth = $tokens->depth;
		$res = new MacroTokens;

		while ($tokens->depth >= $startDepth && $tokens->nextToken()) {
			if (!$tokens->isCurrent($tokens::T_VARIABLE) || $tokens->isPrev('::', '$')) {
				$res->append($tokens->currentToken());
				continue;
			}

			$addBraces = '';
			$expr = new MacroTokens([$tokens->currentToken()]);

			do {
				if ($tokens->nextToken('?->')) {
					$expr->append($tokens->currentToken());
					$expr->append($tokens->nextToken());

				} elseif ($tokens->nextToken('??->')) {
					$expr->prepend('(($ʟ_tmp = ');
					$expr->append(' ?? null) === null ? null : ');
					$res->tokens = array_merge($res->tokens, $expr->tokens);
					$addBraces .= ')';
					$expr = new MacroTokens('$ʟ_tmp->');
					if (!$tokens->nextToken($tokens::T_SYMBOL, $tokens::T_VARIABLE)) {
						$expr->append($addBraces);
						break;
					}

					$expr->append($tokens->currentToken());

				} elseif ($tokens->nextToken('->', '::')) {
					$expr->append($tokens->currentToken());
					if (!$tokens->nextToken($tokens::T_SYMBOL, $tokens::T_VARIABLE)) {
						$expr->append($addBraces);
						break;
					}

					$expr->append($tokens->currentToken());

				} elseif ($tokens->nextToken('[', '(')) {
					$expr->tokens = array_merge($expr->tokens, [$tokens->currentToken()], $this->optionalChainingPass($tokens)->tokens);

				} else {
					$expr->append($addBraces);
					break;
				}
			} while (true);

			$res->tokens = array_merge($res->tokens, $expr->tokens);
		}

		return $res;
	}


	/**
	 * Pseudocast (expand).
	 */
	public function expandCastPass(MacroTokens $tokens): MacroTokens
	{
		$res = new MacroTokens('[');
		$expand = null;
		while ($tokens->nextToken()) {
			if ($tokens->isCurrent('(expand)') && $tokens->depth === 0) {
				$expand = true;
				$res->append('],');
			} elseif ($expand && $tokens->isCurrent(',') && !$tokens->depth) {
				$expand = false;
				$res->append(', [');
			} else {
				$res->append($tokens->currentToken());
			}
		}

		if ($expand === null) {
			$res->append(']');
		} else {
			$res->prepend('array_merge(')->append($expand ? ', [])' : '])');
		}

		return $res;
	}


	/**
	 * Quotes symbols to strings.
	 */
	public function quotingPass(MacroTokens $tokens): MacroTokens
	{
		$res = new MacroTokens;
		while ($tokens->nextToken()) {
			$res->append(
				$tokens->isCurrent($tokens::T_SYMBOL)
				&& (!$tokens->isPrev(...$tokens::SIGNIFICANT) || $tokens->isPrev(',', '(', '[', '=>', ':', '?', '.', '<', '>', '<=', '>=', '===', '!==', '==', '!=', '<>', '&&', '||', '=', 'and', 'or', 'xor', '??'))
				&& (!$tokens->isNext(...$tokens::SIGNIFICANT) || $tokens->isNext(',', ';', ')', ']', '=>', ':', '?', '.', '<', '>', '<=', '>=', '===', '!==', '==', '!=', '<>', '&&', '||', 'and', 'or', 'xor', '??'))
				&& !((!$tokens->isPrev(...$tokens::SIGNIFICANT) || $tokens->isPrev('(', ',')) && $tokens->isNext(':'))
				&& !preg_match('#^[A-Z_][A-Z0-9_]{2,}$#', $tokens->currentValue())
				&& !($tokens->isCurrent('default') && $tokens->isNext('=>'))
					? "'" . $tokens->currentValue() . "'"
					: $tokens->currentToken(),
			);
		}

		return $res;
	}


	/**
	 * Converts named arguments name: value to 'name' => value
	 */
	public function namedArgumentsPass(MacroTokens $tokens): MacroTokens
	{
		$res = new MacroTokens;
		while ($tokens->nextToken()) {
			if (
				$tokens->depth === 0
				&& $tokens->isCurrent($tokens::T_SYMBOL)
				&& (!$tokens->isPrev(...$tokens::SIGNIFICANT) || $tokens->isPrev(','))
				&& $tokens->isNext(':')
			) {
				$res->append("'" . $tokens->currentValue() . "' =>");
				$tokens->nextToken(':');
			} else {
				$res->append($tokens->currentToken());
			}
		}

		return $res;
	}


	/**
	 * Converts [name: value] to ['name' => value]
	 */
	public function modernArraySyntax(MacroTokens $tokens): MacroTokens
	{
		$res = new MacroTokens;
		$brackets = [];
		while ($tokens->nextToken()) {
			if ($tokens->isCurrent('[', '(', '{')) {
				$brackets[] = $tokens->currentValue();
			} elseif ($tokens->isCurrent(']', ')', '}')) {
				array_pop($brackets);
			}

			if (end($brackets) === '['
				&& $tokens->isCurrent($tokens::T_SYMBOL)
				&& ($tokens->isPrev('[', ','))
				&& $tokens->isNext(':')
			) {
				$res->append("'" . $tokens->currentValue() . "' =>");
				$tokens->nextToken(':');
			} else {
				$res->append($tokens->currentToken());
			}
		}

		return $res;
	}


	/**
	 * Syntax $entry in [item1, item2].
	 */
	public function inOperatorPass(MacroTokens $tokens): MacroTokens
	{
		while ($tokens->nextToken()) {
			if ($tokens->isCurrent($tokens::T_VARIABLE)) {
				$start = $tokens->position;
				$depth = $tokens->depth;
				$expr = $arr = [];

				$expr[] = $tokens->currentToken();
				while ($tokens->isNext($tokens::T_VARIABLE, $tokens::T_SYMBOL, $tokens::T_NUMBER, $tokens::T_STRING, '[', ']', '(', ')', '->', '?->')
					&& !$tokens->isNext('in')) {
					$expr[] = $tokens->nextToken();
				}

				if ($depth === $tokens->depth && $tokens->nextValue('in') && ($arr[] = $tokens->nextToken('['))) {
					while ($tokens->isNext(...$tokens::SIGNIFICANT)) {
						$arr[] = $tokens->nextToken();
						if ($tokens->isCurrent(']') && $tokens->depth === $depth) {
							$new = array_merge($tokens->parse('in_array('), $expr, $tokens->parse(', '), $arr, $tokens->parse(', true)'));
							array_splice($tokens->tokens, $start, $tokens->position - $start + 1, $new);
							$tokens->position = $start + count($new) - 1;
							continue 2;
						}
					}
				}

				$tokens->position = $start;
			}
		}

		return $tokens->reset();
	}


	/**
	 * Applies sandbox policy.
	 */
	public function sandboxPass(MacroTokens $tokens): MacroTokens
	{
		static $keywords = [
			'array' => 1, 'catch' => 1, 'clone' => 1, 'empty' => 1, 'for' => 1,
			'foreach' => 1, 'function' => 1, 'if' => 1, 'elseif', 'isset' => 1, 'list' => 1, 'unset' => 1,
		];

		if (!$this->policy) {
			return $tokens;
		}

		$startDepth = $tokens->depth;
		$res = new MacroTokens;

		while ($tokens->depth >= $startDepth && $tokens->nextToken()) {
			$static = false;
			if ($tokens->isCurrent('[', '(')) { // starts with expression
				$expr = new MacroTokens(array_merge([$tokens->currentToken()], $this->sandboxPass($tokens)->tokens));

			} elseif ( // function or class name
				$tokens->isCurrent($tokens::T_SYMBOL, '\\')
				&& empty($keywords[$tokens->currentValue()])
			) {
				$expr = new MacroTokens(array_merge([$tokens->currentToken()], $tokens->nextAll($tokens::T_SYMBOL, '\\')));
				$static = true;

			} elseif ($tokens->isCurrent('$')) { // $$$var or ${...}
				throw new CompileException('Forbidden variable variables.');

			} elseif ($tokens->isCurrent($tokens::T_VARIABLE, $tokens::T_STRING)) {  // $var or 'func'
				$expr = new MacroTokens([$tokens->currentToken()]);

			} else { // not a begin
				$res->append($tokens->currentToken());
				continue;
			}

			do {
				if ($tokens->nextToken('(')) { // call
					if ($static) { // global function
						$name = $expr->joinAll();
						if (!$this->policy->isFunctionAllowed($name)) {
							throw new SecurityViolationException("Function $name() is not allowed.");
						}

						$static = false;
						$expr->append('(');
					} else { // any calling
						$expr->prepend('$this->call(');
						$expr->append(')(');
					}

					$expr->tokens = array_merge($expr->tokens, $this->sandboxPass($tokens)->tokens);

				} elseif ($tokens->nextToken('->', '?->', '::')) { // property, method or constant
					$op = $tokens->currentValue();
					if ($op === '::' && $tokens->nextToken($tokens::T_SYMBOL)) { // is constant?
						if ($tokens->isNext('(')) { // go back, it was not
							$tokens->position--;
						} else { // it is
							$expr->append('::');
							$expr->append($tokens->currentValue());
							continue;
						}
					}

					if ($static) { // class name
						$expr->append('::class');
						$static = false;
					}

					$expr->append(', ');

					if ($tokens->nextToken($tokens::T_SYMBOL)) { // $obj->member or $obj::member
						$member = [$tokens->currentToken()];
						$expr->append(PhpHelpers::dump($tokens->currentValue()));

					} elseif ($tokens->nextToken($tokens::T_VARIABLE)) { // $obj->$var or $obj::$var
						$member = [$tokens->currentToken()];
						if ($op === '::' && !$tokens->isNext('(')) {
							$expr->append(PhpHelpers::dump(substr($tokens->currentValue(), 1)));
						} else {
							$expr->append($tokens->currentValue());
						}
					} elseif ($tokens->nextToken('{')) { // $obj->{...}
						$member = array_merge([$tokens->currentToken()], $this->sandboxPass($tokens)->tokens);
						$expr->append('(string) ');
						$expr->tokens = array_merge($expr->tokens, array_slice($member, 1, -1));

					} else { // $obj->$$$var or $obj::$$$var
						$member = $tokens->nextAll($tokens::T_VARIABLE, '$');
						$expr->tokens = $op === '::' && !$tokens->isNext('(')
							? array_merge($expr->tokens, array_slice($member, 1))
							: array_merge($expr->tokens, $member);
					}

					if ($tokens->nextToken('(')) {
						$expr->prepend('$this->call([');
						$expr->append('])(');
						$expr->tokens = array_merge($expr->tokens, $this->sandboxPass($tokens)->tokens);
					} else {
						$expr->prepend('$this->prop(');
						$expr->append(')' . $op);
						$expr->tokens = array_merge($expr->tokens, $member);
					}
				} elseif ($tokens->nextToken('[', '{')) { // array access
					$static = false;
					$expr->tokens = array_merge($expr->tokens, [$tokens->currentToken()], $this->sandboxPass($tokens)->tokens);

				} else {
					break;
				}
			} while (true);

			$res->tokens = array_merge($res->tokens, $expr->tokens);
		}

		return $res;
	}


	/**
	 * Process inline filters ($var|filter)
	 */
	public function inlineModifierPass(MacroTokens $tokens): MacroTokens
	{
		$result = new MacroTokens;
		while ($tokens->nextToken()) {
			if ($tokens->isCurrent('(', '[')) {
				$result->tokens = array_merge($result->tokens, $this->inlineModifierInner($tokens));
			} else {
				$result->append($tokens->currentToken());
			}
		}

		return $result;
	}


	/**
	 * @return array<array{string, int, int}>
	 */
	private function inlineModifierInner(MacroTokens $tokens): array
	{
		$isFunctionOrArray = $tokens->isPrev($tokens::T_VARIABLE, $tokens::T_SYMBOL, ')') || $tokens->isCurrent('[');
		$result = new MacroTokens;
		$args = new MacroTokens;
		$modifiers = new MacroTokens;
		$current = $args;
		$anyModifier = false;
		$result->append($tokens->currentToken());

		while ($tokens->nextToken()) {
			if ($tokens->isCurrent('(', '[')) {
				$current->tokens = array_merge($current->tokens, $this->inlineModifierInner($tokens));

			} elseif ($current !== $modifiers && $tokens->isCurrent('|')) {
				$anyModifier = true;
				$current = $modifiers;

			} elseif ($tokens->isCurrent(')', ']') || ($isFunctionOrArray && $tokens->isCurrent(','))) {
				$partTokens = count($modifiers->tokens)
					? $this->modifierPass($modifiers, $args->tokens)->tokens
					: $args->tokens;
				$result->tokens = array_merge($result->tokens, $partTokens);
				if ($tokens->isCurrent(',')) {
					$result->append($tokens->currentToken());
					$args = new MacroTokens;
					$modifiers = new MacroTokens;
					$current = $args;
					continue;
				} elseif ($isFunctionOrArray || !$anyModifier) {
					$result->append($tokens->currentToken());
				} else {
					array_shift($result->tokens);
				}

				return $result->tokens;

			} else {
				$current->append($tokens->currentToken());
			}
		}

		throw new CompileException('Unbalanced brackets.');
	}


	/**
	 * Formats modifiers calling.
	 * @param  string|array<array{string, int, int}>  $var
	 * @throws CompileException
	 */
	public function modifierPass(MacroTokens $tokens, string|array $var, bool $isContent = false): MacroTokens
	{
		$inside = 0;
		$res = new MacroTokens($var);
		while ($tokens->nextToken()) {
			if ($tokens->isCurrent($tokens::T_WHITESPACE)) {
				$res->append(' ');

			} elseif ($inside) {
				if ($inside === 1 && $tokens->isCurrent(':')) {
					$res->append(', ');
					$tokens->nextAll($tokens::T_WHITESPACE);
					$inside = 2;

				} elseif ($tokens->isCurrent('|') && !$tokens->depth) {
					$res->append(')');
					$inside = 0;

				} else {
					$res->append($tokens->currentToken());
				}
			} elseif ($tokens->isCurrent($tokens::T_SYMBOL)) {
				if ($tokens->isCurrent('escape')) {
					if ($isContent) {
						$res->prepend('LR\Filters::convertTo($ʟ_fi, ' . PhpHelpers::dump(implode('', $this->context)) . ', ')
							->append(')');
					} else {
						$res = $this->escapePass($res);
					}

					$tokens->nextToken('|');
				} elseif (!strcasecmp($tokens->currentValue(), 'checkurl')) {
					$res->prepend('LR\Filters::safeUrl(');
					$inside = 1;
				} elseif (
					!strcasecmp($tokens->currentValue(), 'noescape')
					|| !strcasecmp($tokens->currentValue(), 'nocheck')
				) {
					throw new SecurityViolationException("Filter |{$tokens->currentValue()} is not expected here.");
				} else {
					$name = $tokens->currentValue();
					if ($this->policy && !$this->policy->isFilterAllowed($name)) {
						throw new SecurityViolationException("Filter |$name is not allowed.");
					}

					$res->prepend(
						$isContent
							? '$this->filters->filterContent(' . PhpHelpers::dump($name) . ', $ʟ_fi, '
							: '($this->filters->' . $name . ')(',
					);
					$inside = 1;
				}
			} else {
				throw new CompileException("Filter name must be alphanumeric string, '{$tokens->currentValue()}' given.");
			}
		}

		if ($inside) {
			$res->append(')');
		}

		return $res;
	}


	private function completeModifier(string $modifier): string
	{
		[$contentType, $context] = $this->context;
		if (
			$contentType === Context::Html
			&& in_array($context, [Context::HtmlAttributeUrl, Context::HtmlAttributeUnquotedUrl], true)
		) {
			if (!Helpers::removeFilter($modifier, 'nocheck')) {
				if (!preg_match('#\|datastream(?=\s|\||$)#Di', $modifier)) {
					$modifier = '|checkurl' . $modifier;
				}
			} elseif ($this->policy && !$this->policy->isFilterAllowed('nocheck')) {
				throw new SecurityViolationException('Filter |nocheck is not allowed.');
			}
		}
		return $modifier;
	}


	/**
	 * Escapes expression in tokens.
	 */
	public function escapePass(MacroTokens $tokens): MacroTokens
	{
		$tokens = clone $tokens;
		[$contentType, $context] = $this->context;
		switch ($contentType) {
			case Context::Html:
				switch ($context) {
					case Context::HtmlText:
						return $tokens->prepend('LR\Filters::escapeHtmlText(')->append(')');
					case Context::HtmlTag:
					case Context::HtmlAttributeUnquotedUrl:
						return $tokens->prepend('LR\Filters::escapeHtmlAttrUnquoted(')->append(')');
					case Context::HtmlAttribute:
					case Context::HtmlAttributeUrl:
						return $tokens->prepend('LR\Filters::escapeHtmlAttr(')->append(')');
					case Context::HtmlAttributeJavaScript:
						return $tokens->prepend('LR\Filters::escapeHtmlAttr(LR\Filters::escapeJs(')->append('))');
					case Context::HtmlAttributeCss:
						return $tokens->prepend('LR\Filters::escapeHtmlAttr(LR\Filters::escapeCss(')->append('))');
					case Context::HtmlComment:
						return $tokens->prepend('LR\Filters::escapeHtmlComment(')->append(')');
					case Context::HtmlBogusTag:
						return $tokens->prepend('LR\Filters::escapeHtml(')->append(')');
					case Context::HtmlJavaScript:
					case Context::HtmlCss:
						return $tokens->prepend('LR\Filters::escape' . ucfirst($context) . '(')->append(')');
					default:
						throw new CompileException("Unknown context $contentType, $context.");
				}
				// break omitted
			case Context::Xml:
				switch ($context) {
					case Context::XmlText:
					case Context::XmlAttribute:
					case Context::XmlBogusTag:
						return $tokens->prepend('LR\Filters::escapeXml(')->append(')');
					case Context::XmlComment:
						return $tokens->prepend('LR\Filters::escapeHtmlComment(')->append(')');
					case Context::XmlTag:
						return $tokens->prepend('LR\Filters::escapeXmlAttrUnquoted(')->append(')');
					default:
						throw new CompileException("Unknown context $contentType, $context.");
				}
				// break omitted
			case Context::JavaScript:
			case Context::Css:
			case Context::ICal:
				return $tokens->prepend('LR\Filters::escape' . ucfirst($contentType) . '(')->append(')');
			case Context::Text:
				return $tokens;
			case null:
				return $tokens->prepend('($this->filters->escape)(')->append(')');
			default:
				throw new CompileException("Unknown context $contentType.");
		}
	}
}
