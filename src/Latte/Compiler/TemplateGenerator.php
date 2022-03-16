<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Compiler;

use Latte\CompileException;
use Latte\Context;
use Latte\Extension;
use Latte\Helpers;
use Latte\Policy;
use Latte\SecurityViolationException;
use Latte\Strict;


/**
 * Template code generator.
 */
final class TemplateGenerator
{
	use Strict;

	/** @var string[] @internal */
	public array $placeholders = [];

	public ?string $paramsExtraction = null;

	/** @var LegacyToken[] */
	private array $tokens;

	/** pointer to current node content */
	private ?string $output;

	/** position on source template */
	private int $position = 0;

	/** @var array<string, Extension[]> */
	private array $macros = [];

	/** @var string[] of orig name */
	private array $functions = [];

	/** @var int[] Macro flags */
	private array $flags;

	private ?HtmlNode $htmlNode = null;

	private ?Tag $macroNode = null;

	private string $contentType = Context::Html;

	private ?string $context = null;

	private mixed $lastAttrValue = null;

	private int $tagOffset;

	private bool $inHead;

	/** @var array<string, ?array{body: string, arguments: string, returns: string, comment: ?string}> */
	private array $methods = [];

	/** @var array<string, mixed> */
	private array $properties = [];

	/** @var array<string, mixed> */
	private array $constants = [];

	private ?Policy $policy = null;


	/**
	 * Adds new macro with Macro flags.
	 */
	public function addMacro(string $name, Extension $macro, ?int $flags = null): static
	{
		if (!preg_match('#^[a-z_=]\w*(?:[.:-]\w+)*$#iD', $name)) {
			throw new \LogicException("Invalid tag name '$name'.");

		} elseif (!isset($this->flags[$name])) {
			$this->flags[$name] = $flags ?: Extension::DEFAULT_FLAGS;

		} elseif ($flags && $this->flags[$name] !== $flags) {
			throw new \LogicException("Incompatible flags for tag '$name'.");
		}

		$this->macros[$name][] = $macro;
		return $this;
	}


	/**
	 * Registers run-time functions.
	 * @param  string[]  $names
	 */
	public function setFunctions(array $names): static
	{
		$this->functions = array_combine(array_map('strtolower', $names), $names);
		return $this;
	}


	/**
	 * Compiles tokens to PHP file
	 * @param  LegacyToken[]  $tokens
	 */
	public function compile(array $tokens, string $className, ?string $comment = null, bool $strictMode = false): string
	{
		$code = "<?php\n\n"
			. ($strictMode ? "declare(strict_types=1);\n\n" : '')
			. "use Latte\\Runtime as LR;\n\n"
			. ($comment === null ? '' : '/** ' . str_replace('*/', '* /', $comment) . " */\n")
			. "final class $className extends Latte\\Runtime\\Template\n{\n"
			. $this->buildClassBody($tokens)
			. "\n\n}\n";

		$code = PhpHelpers::inlineHtmlToEcho($code);
		$code = PhpHelpers::reformatCode($code);
		return $code;
	}


	/**
	 * @param  LegacyToken[]  $tokens
	 */
	private function buildClassBody(array $tokens): string
	{
		$this->tokens = $tokens;
		$output = '';
		$this->output = &$output;
		$this->inHead = true;
		$this->htmlNode = $this->macroNode = $this->context = $this->paramsExtraction = null;
		$this->placeholders = $this->properties = $this->constants = [];
		$this->methods = ['main' => null, 'prepare' => null];

		$macroHandlers = new \SplObjectStorage;

		if ($this->macros) {
			array_map([$macroHandlers, 'attach'], array_merge(...array_values($this->macros)));
		}

		foreach ($macroHandlers as $handler) {
			$handler->beforeCompile();
		}

		foreach ($tokens as $this->position => $token) {
			if ($this->inHead && !(
				$token->type === $token::COMMENT
				|| $token->type === $token::MACRO_TAG && ($this->flags[$token->name] ?? null) & Extension::ALLOWED_IN_HEAD
				|| $token->type === $token::TEXT && trim($token->text) === ''
			)) {
				$this->inHead = false;
			}

			$this->{"process$token->type"}($token);
		}

		while ($this->htmlNode) {
			if (!empty($this->htmlNode->macroAttrs)) {
				throw new CompileException('Missing ' . self::printEndTag($this->htmlNode));
			}

			$this->htmlNode = $this->htmlNode->parentNode;
		}

		while ($this->macroNode) {
			if ($this->macroNode->parent) {
				throw new CompileException('Missing {/' . $this->macroNode->name . '}');
			}

			if (~$this->flags[$this->macroNode->name] & Extension::AUTO_CLOSE) {
				throw new CompileException('Missing ' . self::printEndTag($this->macroNode));
			}

			$this->closeMacro($this->macroNode->name);
		}

		$prepare = $epilogs = '';
		foreach ($macroHandlers as $handler) {
			$res = $handler->finalize($this);
			$prepare .= empty($res[0]) ? '' : "<?php $res[0] ?>";
			$epilogs = (empty($res[1]) ? '' : "<?php $res[1] ?>") . $epilogs;
		}

		$extractParams = $this->paramsExtraction ?? 'extract($this->params);';
		$this->addMethod('main', $this->expandTokens($extractParams . "?>\n$output$epilogs<?php return get_defined_vars();"), '', 'array');

		if ($prepare) {
			$this->addMethod('prepare', $extractParams . "?>$prepare<?php", '', 'void');
		}

		if ($this->contentType !== Context::Html) {
			$this->addConstant('ContentType', $this->contentType);
		}

		$members = [];
		foreach ($this->constants as $name => $value) {
			$members[] = "\tprotected const $name = " . PhpHelpers::dump($value, true) . ';';
		}

		foreach ($this->properties as $name => $value) {
			$members[] = "\tpublic $$name = " . PhpHelpers::dump($value, true) . ';';
		}

		foreach (array_filter($this->methods) as $name => $method) {
			$members[] = ($method['comment'] === null ? '' : "\n\t/** " . str_replace('*/', '* /', $method['comment']) . ' */')
				. "\n\tpublic function $name($method[arguments])"
				. ($method['returns'] ? ': ' . $method['returns'] : '')
				. "\n\t{\n"
				. ($method['body'] ? "\t\t$method[body]\n" : '') . "\t}";
		}

		return implode("\n\n", $members);
	}


	public function setPolicy(?Policy $policy): static
	{
		$this->policy = $policy;
		return $this;
	}


	public function getPolicy(): ?Policy
	{
		return $this->policy;
	}


	public function setContentType(string $type): static
	{
		$this->contentType = $type;
		$this->context = null;
		return $this;
	}


	public function getContentType(): string
	{
		return $this->contentType;
	}


	public function getMacroNode(): ?Tag
	{
		return $this->macroNode;
	}


	/**
	 * @return Extension[][]
	 */
	public function getMacros(): array
	{
		return $this->macros;
	}


	/**
	 * @return string[]
	 */
	public function getFunctions(): array
	{
		return $this->functions;
	}


	/**
	 * Returns current line number.
	 */
	public function getLine(): ?int
	{
		return isset($this->tokens[$this->position])
			? $this->tokens[$this->position]->line
			: null;
	}


	public function isInHead(): bool
	{
		return $this->inHead;
	}


	/**
	 * Adds custom method to template.
	 * @internal
	 */
	public function addMethod(
		string $name,
		string $body,
		string $arguments = '',
		string $returns = '',
		?string $comment = null,
	): void {
		$body = trim($body);
		$this->methods[$name] = compact('body', 'arguments', 'returns', 'comment');
	}


	/**
	 * Returns custom methods.
	 * @return array<string, ?array{body: string, arguments: string, returns: string, comment: ?string}>
	 * @internal
	 */
	public function getMethods(): array
	{
		return $this->methods;
	}


	/**
	 * Adds custom property to template.
	 * @internal
	 */
	public function addProperty(string $name, mixed $value): void
	{
		$this->properties[$name] = $value;
	}


	/**
	 * Returns custom properites.
	 * @return array<string, mixed>
	 * @internal
	 */
	public function getProperties(): array
	{
		return $this->properties;
	}


	/**
	 * Adds custom constant to template.
	 * @internal
	 */
	public function addConstant(string $name, mixed $value): void
	{
		$this->constants[$name] = $value;
	}


	/** @internal */
	public function expandTokens(string $s): string
	{
		return strtr($s, $this->placeholders);
	}


	private function processText(LegacyToken $token): void
	{
		if (
			$this->lastAttrValue === ''
			&& $this->context
			&& str_starts_with($this->context, Context::HtmlAttribute)
		) {
			$this->lastAttrValue = $token->text;
		}

		$this->output .= $this->escape($token->text);
	}


	private function processMacroTag(LegacyToken $token): void
	{
		if (
			$this->context === Context::HtmlTag
			|| $this->context
			&& str_starts_with($this->context, Context::HtmlAttribute)
		) {
			$this->lastAttrValue = true;
		}

		$isRightmost = !isset($this->tokens[$this->position + 1])
			|| substr($this->tokens[$this->position + 1]->text, 0, 1) === "\n";

		if ($token->closing) {
			$this->closeMacro($token->name, $token->value, $token->modifiers, $isRightmost);
		} else {
			$node = $this->openMacro($token->name, $token->value, $token->modifiers, $isRightmost);
			if ($token->empty) {
				if ($node->void) {
					throw new CompileException("Unexpected /} in tag {$token->text}");
				}

				$this->closeMacro($token->name, '', '', $isRightmost);
			}
		}
	}


	private function processHtmlTagBegin(LegacyToken $token): void
	{
		if ($token->closing) {
			while ($this->htmlNode) {
				if (strcasecmp($this->htmlNode->name, $token->name) === 0) {
					break;
				}

				if ($this->htmlNode->macroAttrs) {
					throw new CompileException("Unexpected </$token->name>, expecting " . self::printEndTag($this->htmlNode));
				}

				$this->htmlNode = $this->htmlNode->parentNode;
			}

			if (!$this->htmlNode) {
				$this->htmlNode = new HtmlNode($token->name);
			}

			$this->htmlNode->empty = false;
			$this->htmlNode->closing = true;
			$this->context = Context::HtmlText;

		} elseif ($token->text === '<!--') {
			$this->context = Context::HtmlComment;

		} elseif ($token->text === '<?' || $token->text === '<!') {
			$this->context = Context::HtmlBogusTag;

		} else {
			$this->htmlNode = new HtmlNode($token->name, $this->htmlNode);
			$this->htmlNode->line = $this->getLine();
			$this->context = Context::HtmlTag;
		}

		$this->tagOffset = strlen($this->output);
		$this->output .= $this->escape($token->text);
	}


	private function processHtmlTagEnd(LegacyToken $token): void
	{
		if (in_array($this->context, [Context::HtmlComment, Context::HtmlBogusTag], true)) {
			$this->output .= $token->text;
			$this->context = Context::HtmlText;
			return;
		}

		$htmlNode = $this->htmlNode;
		$end = '';

		if (!$htmlNode->closing) {
			$htmlNode->empty = str_contains($token->text, '/');
			if ($this->contentType === Context::Html) {
				$emptyElement = isset(Helpers::$emptyElements[strtolower($htmlNode->name)]);
				$htmlNode->empty = $htmlNode->empty || $emptyElement;
				if ($htmlNode->empty && !$emptyElement) { // auto-correct
					$space = substr(strstr($token->text, '>'), 1);
					$token->text = '>';
					$end = "</$htmlNode->name>" . $space;
				}
			}
		}

		if ($htmlNode->macroAttrs) {
			$html = substr($this->output, $this->tagOffset) . $token->text;
			$this->output = substr($this->output, 0, $this->tagOffset);
			$this->writeAttrsMacro($html, $emptyElement ?? null);
		} else {
			$this->output .= $token->text . $end;
		}

		if ($htmlNode->empty) {
			$htmlNode->closing = true;
			if ($htmlNode->macroAttrs) {
				$this->writeAttrsMacro($end);
			}
		}

		$this->context = Context::HtmlText;

		if ($htmlNode->closing) {
			$this->htmlNode = $this->htmlNode->parentNode;

		} elseif (
			(($lower = strtolower($htmlNode->name)) === 'script' || $lower === 'style')
			&& (!isset($htmlNode->attrs['type']) || preg_match('#(java|j|ecma|live)script|module|json|css|plain#i', $htmlNode->attrs['type']))
		) {
			$this->context = $lower === 'script'
				? Context::HtmlJavaScript
				: Context::HtmlCss;
		}
	}


	private function processHtmlAttributeBegin(LegacyToken $token): void
	{
		if (str_starts_with($token->name, TemplateLexer::NPrefix)) {
			$name = substr($token->name, strlen(TemplateLexer::NPrefix));
			if (isset($this->htmlNode->macroAttrs[$name])) {
				throw new CompileException("Found multiple attributes {$token->name}.");

			} elseif ($this->macroNode && $this->macroNode->htmlElement === $this->htmlNode) {
				throw new CompileException("n:attribute must not appear inside tags; found {$token->name} inside {{$this->macroNode->name}}.");
			}

			$this->htmlNode->macroAttrs[$name] = $token->value;
			return;
		}

		$this->lastAttrValue = &$this->htmlNode->attrs[$token->name];
		$this->output .= $this->escape($token->text);

		$lower = strtolower($token->name);
		if (in_array($token->value, ['"', "'"], true)) {
			$this->lastAttrValue = '';
			$this->context = Context::HtmlAttribute;
			if ($this->contentType === Context::Html) {
				if (str_starts_with($lower, 'on')) {
					$this->context = Context::HtmlAttributeJavaScript;
				} elseif ($lower === 'style') {
					$this->context = Context::HtmlAttributeCss;
				}
			}
		} else {
			$this->lastAttrValue = $token->value;
			$this->context = Context::HtmlTag;
		}

		if (
			$this->contentType === Context::Html
			&& (in_array($lower, ['href', 'src', 'action', 'formaction'], true)
				|| ($lower === 'data' && strtolower($this->htmlNode->name) === 'object'))
		) {
			$this->context = $this->context === Context::HtmlTag
				? Context::HtmlAttributeUnquotedUrl
				: Context::HtmlAttributeUrl;
		}
	}


	private function processHtmlAttributeEnd(LegacyToken $token): void
	{
		$this->context = Context::HtmlTag;
		$this->output .= $token->text;
	}


	private function processComment(LegacyToken $token): void
	{
		$leftOfs = ($tmp = strrpos($this->output, "\n")) === false ? 0 : $tmp + 1;
		$isLeftmost = trim(substr($this->output, $leftOfs)) === '';
		$isRightmost = substr($token->text, -1) === "\n";
		if ($isLeftmost && $isRightmost) {
			$this->output = substr($this->output, 0, $leftOfs);
		} else {
			$this->output .= substr($token->text, strlen(rtrim($token->text, "\n")));
		}
	}


	private function escape(string $s): string
	{
		return substr(str_replace('<?', '<<?php ?>?', $s . '?'), 0, -1);
	}


	/********************* macros ****************d*g**/


	/**
	 * Generates code for {macro ...} to the output.
	 * @internal
	 */
	public function openMacro(
		string $name,
		string $args = '',
		string $modifiers = '',
		bool $isRightmost = false,
		?string $nPrefix = null,
	): Tag {
		$node = $this->expandMacro($name, $args, $modifiers, $nPrefix);
		if ($node->void) {
			$this->writeCode((string) $node->openingCode, $node->replaced, $isRightmost);
			if ($node->isNAttribute() && $node->prefix !== Tag::PrefixTag) {
				$this->htmlNode->attrCode .= $node->attrCode;
			}
		} else {
			$this->macroNode = $node;
			$node->saved = [&$this->output, $isRightmost];
			$this->output = &$node->content;
			$this->output = '';
		}

		return $node;
	}


	/**
	 * Generates code for {/macro ...} to the output.
	 * @internal
	 */
	public function closeMacro(
		string $name,
		string $args = '',
		string $modifiers = '',
		bool $isRightmost = false,
		?string $nPrefix = null,
	): Tag {
		$node = $this->macroNode;

		if (
			!$node
			|| ($node->name !== $name && $name !== '')
			|| $modifiers
			|| ($args !== '' && $node->args !== '' && !str_starts_with($node->args . ' ', $args . ' '))
			|| $nPrefix !== $node->prefix
		) {
			$name = $nPrefix
				? "</{$this->htmlNode->name}> for " . TemplateLexer::NPrefix . implode(' and ' . TemplateLexer::NPrefix, array_keys($this->htmlNode->macroAttrs))
				: '{/' . $name . ($args ? ' ' . $args : '') . $modifiers . '}';
			throw new CompileException("Unexpected $name" . ($node ? ', expecting ' . self::printEndTag($node->isNAttribute() ? $this->htmlNode : $node) : ''));
		}

		$this->macroNode = $node->parent;
		if ($node->args === '') {
			$node->setArgs($args);
		}

		if ($node->prefix === Tag::PrefixNone) {
			$parts = explode($node->htmlElement->innerMarker, $node->content);
			if (count($parts) === 3) { // markers may be destroyed by inner macro
				$node->innerContent = $parts[1];
			}
		}

		$node->closing = true;
		$node->macro->nodeClosed($node);

		if (isset($parts[1]) && $node->innerContent !== $parts[1]) {
			$node->content = implode($node->htmlElement->innerMarker, [$parts[0], $node->innerContent, $parts[2]]);
		}

		if ($node->isNAttribute() && $node->prefix !== Tag::PrefixTag) {
			$this->htmlNode->attrCode .= $node->attrCode;
		}

		$this->output = &$node->saved[0];
		$this->writeCode((string) $node->openingCode, $node->replaced, $node->saved[1]);
		$this->output .= $node->content;
		$this->writeCode((string) $node->closingCode, $node->replaced, $isRightmost, true);
		return $node;
	}


	private function writeCode(string $code, ?bool $isReplaced, ?bool $isRightmost, bool $isClosing = false): void
	{
		if ($isRightmost) {
			$leftOfs = ($tmp = strrpos($this->output, "\n")) === false ? 0 : $tmp + 1;
			$isLeftmost = trim(substr($this->output, $leftOfs)) === '';
			if ($isReplaced === null) {
				$isReplaced = preg_match('#<\?php.*\secho\s#As', $code);
			}

			if ($isLeftmost && !$isReplaced) {
				$this->output = substr($this->output, 0, $leftOfs); // alone macro without output -> remove indentation
				if (!$isClosing && substr($code, -2) !== '?>') {
					$code .= '<?php ?>'; // consume new line
				}
			} elseif (substr($code, -2) === '?>') {
				$code .= "\n"; // double newline to avoid newline eating by PHP
			}
		}

		$this->output .= $code;
	}


	/**
	 * Generates code for macro <tag n:attr> to the output.
	 * @internal
	 */
	public function writeAttrsMacro(string $html, ?bool $empty = null): void
	{
		//     none-2 none-1 tag-1 tag-2       <el attr-1 attr-2>   /tag-2 /tag-1 [none-2] [none-1] inner-2 inner-1
		// /inner-1 /inner-2 [none-1] [none-2] tag-1 tag-2  </el>   /tag-2 /tag-1 /none-1 /none-2
		$attrs = $this->htmlNode->macroAttrs;
		$left = $right = [];

		foreach ($this->macros as $name => $foo) {
			$attrName = Tag::PrefixInner . "-$name";
			if (!isset($attrs[$attrName])) {
				continue;
			}
			if ($empty) {
				trigger_error("Unexpected n:$attrName on void element <{$this->htmlNode->name}>", E_USER_WARNING);
			}

			if ($this->htmlNode->closing) {
				$left[] = function () use ($name) {
					$this->closeMacro($name, '', '', false, Tag::PrefixInner);
				};
			} else {
				array_unshift($right, function () use ($name, $attrs, $attrName) {
					if ($this->openMacro($name, $attrs[$attrName], '', false, Tag::PrefixInner)->void) {
						throw new CompileException("Unexpected prefix in n:$attrName.");
					}
				});
			}

			unset($attrs[$attrName]);
		}

		$innerMarker = '';
		if ($this->htmlNode->closing) {
			$left[] = function () {
				$this->output .= $this->htmlNode->innerMarker;
			};
		} else {
			array_unshift($right, function () use (&$innerMarker) {
				$this->output .= $innerMarker;
			});
		}

		foreach (array_reverse($this->macros) as $name => $foo) {
			$attrName = Tag::PrefixTag . "-$name";
			if (!isset($attrs[$attrName])) {
				continue;
			}
			if ($empty) {
				trigger_error("Unexpected n:$attrName on void element <{$this->htmlNode->name}>", E_USER_WARNING);
			}

			$left[] = function () use ($name, $attrs, $attrName) {
				if ($this->openMacro($name, $attrs[$attrName], '', false, Tag::PrefixTag)->void) {
					throw new CompileException("Unexpected prefix in n:$attrName.");
				}
			};
			array_unshift($right, function () use ($name) {
				$this->closeMacro($name, '', '', false, Tag::PrefixTag);
			});
			unset($attrs[$attrName]);
		}

		foreach ($this->macros as $name => $foo) {
			if (isset($attrs[$name])) {
				if ($this->htmlNode->closing) {
					$right[] = function () use ($name) {
						$this->closeMacro($name, '', '', false, Tag::PrefixNone);
					};
				} else {
					array_unshift($left, function () use ($name, $attrs, &$innerMarker) {
						$node = $this->openMacro($name, $attrs[$name], '', false, Tag::PrefixNone);
						if ($node->void) {
							unset($this->htmlNode->macroAttrs[$name]); // don't call closeMacro
						} elseif (!$innerMarker) {
							$this->htmlNode->innerMarker = $innerMarker = '<n:q' . count($this->placeholders) . 'q>';
							$this->placeholders[$innerMarker] = '';
						}
					});
				}

				unset($attrs[$name]);
			}
		}

		if ($attrs) {
			throw new CompileException(
				'Unknown attribute ' . TemplateLexer::NPrefix
				. implode(' and ' . TemplateLexer::NPrefix, array_keys($attrs))
				. (($t = Helpers::getSuggestion(array_keys($this->macros), key($attrs))) ? ', did you mean ' . TemplateLexer::NPrefix . $t . '?' : ''),
			);
		}

		if (!$this->htmlNode->closing) {
			$this->htmlNode->attrCode = &$this->placeholders[$uniq = ' n:q' . count($this->placeholders) . 'q'];
			$html = substr_replace($html, $uniq, strrpos($html, '/>') ?: strrpos($html, '>'), 0);
		}

		foreach ($left as $func) {
			$func();
		}

		$this->output .= $html;

		foreach ($right as $func) {
			$func();
		}

		if ($right && substr($this->output, -2) === '?>') {
			$this->output .= "\n";
		}
	}


	/**
	 * Expands macro and returns node & code.
	 * @internal
	 */
	public function expandMacro(string $name, string $args, string $modifiers = '', ?string $nPrefix = null): Tag
	{
		if (empty($this->macros[$name])) {
			$hint = (($t = Helpers::getSuggestion(array_keys($this->macros), $name)) ? ", did you mean {{$t}}?" : '')
				. (in_array($this->context, [Context::HtmlJavaScript, Context::HtmlCss], true) ? ' (in JavaScript or CSS, try to put a space after bracket or use n:syntax=off)' : '');
			throw new CompileException("Unknown tag {{$name}}$hint");

		} elseif ($this->policy && !$this->policy->isTagAllowed($name)) {
			throw new SecurityViolationException('Tag ' . ($nPrefix ? "n:$name" : "{{$name}}") . ' is not allowed.');
		}

		if (strpbrk($name, '=~%^&_')) {
			if (!Helpers::removeFilter($modifiers, 'noescape')) {
				$modifiers .= '|escape';
			} elseif ($this->policy && !$this->policy->isFilterAllowed('noescape')) {
				throw new SecurityViolationException('Filter |noescape is not allowed.');
			}

			if (
				$this->context === Context::HtmlJavaScript
				&& $name === '='
				&& preg_match('#["\']$#D', $this->tokens[$this->position - 1]->text)
			) {
				throw new CompileException("Do not place {$this->tokens[$this->position]->text} inside quotes in JavaScript.");
			}
		}

		if ($nPrefix === Tag::PrefixInner && !strcasecmp($this->htmlNode->name, 'script')) {
			$context = [$this->contentType, Context::HtmlJavaScript];
		} elseif ($nPrefix === Tag::PrefixInner && !strcasecmp($this->htmlNode->name, 'style')) {
			$context = [$this->contentType, Context::HtmlCss];
		} elseif ($nPrefix) {
			$context = [$this->contentType, Context::HtmlText];
		} else {
			$context = [$this->contentType, $this->context];
		}

		foreach (array_reverse($this->macros[$name]) as $macro) {
			$node = new Tag(
				$name,
				$args,
				$modifiers,
				line: $nPrefix ? $this->htmlNode->line : $this->getLine(),
				parent: $this->macroNode,
				htmlElement: $this->htmlNode,
				prefix: $nPrefix,
			);
			$node->macro = $macro;
			$node->context = $context;
			if ($macro->nodeOpened($node) !== false) {
				return $node;
			}
		}

		throw new CompileException('Unknown ' . ($nPrefix
			? 'attribute ' . TemplateLexer::NPrefix . ($nPrefix === Tag::PrefixNone ? '' : "$nPrefix-") . $name
			: 'tag {' . $name . ($args ? " $args" : '') . '}'
		));
	}


	private static function printEndTag(HtmlNode|Tag $node): string
	{
		return $node instanceof HtmlNode
			? "</{$node->name}> for " . TemplateLexer::NPrefix . implode(' and ' . TemplateLexer::NPrefix, array_keys($node->macroAttrs))
			: "{/{$node->name}}";
	}
}
