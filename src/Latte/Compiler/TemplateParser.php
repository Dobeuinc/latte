<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Compiler;

use Latte\CompileException;
use Latte\Compiler\Nodes\FragmentNode;
use Latte\Context;
use Latte\Helpers;
use Latte\Policy;
use Latte\Runtime\Template;
use Latte\SecurityViolationException;
use Latte\Strict;


final class TemplateParser
{
	use Strict;

	public const
		LocationHead = 1,
		LocationText = 2,
		LocationTag = 3;

	/** @var Block[][] */
	public array $blocks = [[]];
	public int $blockLayer = Template::LayerTop;
	public int $location = self::LocationHead;
	public ?Nodes\TextNode $lastIndentation = null;

	/** @var array<string, callable(Tag, self): (Node|\Generator|void)> */
	private array $tagParsers = [];

	/** @var array<string, callable(Tag, self): (Node|\Generator|void)> */
	private array $attrParsers = [];

	private TemplateParserHtml $html;
	private ?TokenStream $stream = null;
	private ?TemplateLexer $lexer = null;
	private ?Policy $policy = null;
	private string $contentType = Context::Html;
	private int $counter = 0;
	private ?Tag $tag = null;
	private $lastResolver;


	/** @param  array<string, callable(Tag, self): (Node|\Generator|void)>  $parsers */
	public function addTags(array $parsers): static
	{
		foreach ($parsers as $name => $parser) {
			if (str_starts_with($name, 'n:')) {
				$this->attrParsers[substr($name, 2)] = $parser;
			} else {
				$this->tagParsers[$name] = $parser;
				if (Helpers::toReflection($parser)->isGenerator()) {
					$this->attrParsers[$name] = $parser;
					$this->attrParsers[Tag::PrefixInner . '-' . $name] = $parser;
					$this->attrParsers[Tag::PrefixTag . '-' . $name] = $parser;
				}
			}
		}

		return $this;
	}


	/**
	 * Parses tokens to nodes.
	 * @throws CompileException
	 */
	public function parse(string $template, TemplateLexer $lexer): Nodes\TemplateNode
	{
		$this->lexer = $lexer;
		$this->html = new TemplateParserHtml($this, $this->attrParsers);
		$this->stream = new TokenStream($lexer->tokenize($template, $this->contentType));

		$headLength = 0;
		$node = new Nodes\TemplateNode;
		$node->main = $this->parseFragment([$this->html, 'inTextResolve'], $headLength);
		$node->head = new FragmentNode(array_splice($node->main->children, 0, $headLength));
		if ($this->stream->current()) {
			$this->stream->throwUnexpectedException();
		}

		return $node;
	}


	public function parseFragment(callable $resolver, &$headLength = null): FragmentNode
	{
		$res = new FragmentNode;
		$prev = $this->lastResolver;
		$this->lastResolver = $resolver;
		while ($this->stream->current()) {
			if ($node = $resolver()) {
				$res->append($node);
				if (
					$this->location === self::LocationHead
					&& !$node instanceof Nodes\TextNode
					&& !$node instanceof Nodes\NopNode
				) {
					$headLength = count($res->children);
				}
			} else {
				break;
			}
		}

		$this->lastResolver = $prev;
		return $res;
	}


	public function inTextResolve(): ?Node
	{
		$token = $this->stream->current();
		return match ($token->type) {
			Token::Text => $this->parseText(),
			Token::Indentation => $this->parseIndentation(),
			Token::Newline => $this->parseNewline(),
			Token::Latte_CommentOpen => $this->parseLatteComment(),
			Token::Latte_TagOpen => $this->stream->peek(1)->is(Token::Slash)
				? null // TODO: error uvnitr HTML?
				: $this->parseLatteStatement(),
			default => null,
		};
	}


	private function parseText(): Nodes\TextNode
	{
		$token = $this->stream->consume(Token::Text);
		if ($this->location === self::LocationHead && trim($token->text) !== '') {
			$this->location = self::LocationText;
		}
		$this->lastIndentation = null;
		return new Nodes\TextNode($token->text, $token->line);
	}


	private function parseIndentation(): Nodes\TextNode
	{
		$token = $this->stream->consume(Token::Indentation);
		return $this->lastIndentation = new Nodes\TextNode($token->text, $token->line);
	}


	private function parseNewline(): Nodes\AreaNode
	{
		$token = $this->stream->consume(Token::Newline);
		if ($this->lastIndentation) { // drop indentation & newline
			$this->lastIndentation->content = '';
			$this->lastIndentation = null;
			return new Nodes\NopNode;
		} else {
			return new Nodes\TextNode($token->text, $token->line);
		}
	}


	private function parseLatteComment(): Nodes\NopNode
	{
		if (str_ends_with($this->stream->peek(-1)?->text ?? "\n", "\n")) {
			$this->lastIndentation ??= new Nodes\TextNode('');
		}
		$this->stream->consume(Token::Latte_CommentOpen);
		$this->stream->consume(Token::Text);
		$this->stream->consume(Token::Latte_CommentClose);
		return new Nodes\NopNode;
	}


	private function parseLatteStatement(): ?Node
	{
		if (isset($this->tag->data->filters)
			&& in_array($this->stream->peek(1)?->text, $this->tag->data->filters, true)
		) {
			return null; // go back to previous parseLatteStatement()
		}

		$token = $this->stream->current();
		$checkConsumed[] = $startTag = $this->pushTag($this->parseLatteTag());

		$parser = $this->getTagParser($startTag->name, $token->line, $token->column);
		$res = $parser($startTag, $this);
		if ($res instanceof \Generator) {
			if (!$res->valid() && !$startTag->void) {
				throw new \LogicException("Incorrect behavior of {{$startTag->name}} parser, yield call is expected (on line $startTag->startLine)");
			}

			if ($startTag->outputMode === $startTag::OutputKeepIndentation) {
				$this->lastIndentation = null;
			}

			if ($startTag->void) {
				$res->send([new FragmentNode, $startTag]);
			} else {
				while ($res->valid()) {
					$startTag->data->filters = $res->current() ?: null;
					$content = $this->parseFragment($this->lastResolver);

					if (!$this->stream->is(Token::Latte_TagOpen)) {
						$this->checkEndTag($startTag, $endTag = null);
						$res->send([$content, null]);
						break;
					}

					$checkConsumed[] = $tag = $this->parseLatteTag();

					if ($startTag->outputMode === $startTag::OutputKeepIndentation) {
						$this->lastIndentation = null;
					}

					if ($tag->closing) {
						$this->checkEndTag($startTag, $endTag = $tag);
						$res->send([$content, $tag]);
						break;
					} else {
						$this->pushTag($tag);
						$res->send([$content, $tag]);
						$this->popTag();
					}
				}
			}

			if ($res->valid()) {
				throw new \LogicException("Incorrect behavior of {{$startTag->name}} parser, more yield calls than expected (on line $startTag->startLine)");
			}

			$node = $res->getReturn();

		} elseif ($startTag->void) {
			throw new CompileException('Unexpected /} in tag ' . substr($startTag->getNotation(true), 0, -1) . '/}', $startTag->startLine);

		} else {
			$node = $res;
			if ($startTag->outputMode === $startTag::OutputKeepIndentation) {
				$this->lastIndentation = null;
			}
		}

		if (!$node instanceof Node) {
			throw new \LogicException("Incorrect behavior of {{$startTag->name}} parser, unexpected returned value (on line $startTag->startLine)");
		}

		foreach ($checkConsumed as $tmp) {
			$this->expectConsumed($tmp);
		}

		if ($this->location === self::LocationHead && $startTag->outputMode !== $startTag::OutputNone) {
			$this->location = self::LocationText;
		}

		$this->popTag();

		$node->startLine = $startTag->startLine;
		$node->endLine = ($endTag ?? $startTag)->endLine;
		return $node;
	}


	private function parseLatteTag(): Tag
	{
		$stream = $this->stream;
		if (str_ends_with($stream->peek(-1)?->text ?? "\n", "\n")) {
			$this->lastIndentation ??= new Nodes\TextNode('');
		}

		$openToken = $stream->consume(Token::Latte_TagOpen);
		return new Tag(
			startLine: $openToken->line,
			closing: $closing = (bool) $stream->tryConsume(Token::Slash),
			name: $stream->tryConsume(Token::Latte_Name)?->text ?? ($closing ? '' : '='),
			tokens: $this->consumeTag(),
			void: (bool) $stream->tryConsume(Token::Slash),
			location: $this->location,
			htmlElement: $this->html->getElement(),
			endLine: $stream->consume(Token::Latte_TagClose)->line,
		);
	}


	private function consumeTag(): array
	{
		$res = [];
		while ($this->stream->peek(0)?->isPhpKind()) {
			$res[] = $this->stream->consume();
		}

		return $res;
	}


	/** @return callable(Tag, self): (Node|\Generator|void) */
	private function getTagParser(string $name, int $line, int $column): callable
	{
		if (!isset($this->tagParsers[$name])) {
			$hint = ($t = Helpers::getSuggestion(array_keys($this->tagParsers), $name))
				? ", did you mean {{$t}}?"
				: '';
			if ($this->contentType === Context::Html
				&& in_array($this->html->getElement()?->name, ['script', 'style'], true)
			) {
				$hint .= ' (in JavaScript or CSS, try to put a space after bracket or use n:syntax=off)';
			}
			throw new CompileException("Unexpected tag {{$name}}$hint", $line, $column);
		} elseif (!$this->isTagAllowed($name)) {
			throw new SecurityViolationException("Tag {{$name}} is not allowed.");
		}

		return $this->tagParsers[$name];
	}


	private function checkEndTag(Tag $start, ?Tag $end): void
	{
		if ($start->name === 'syntax'
			|| $start->name === 'block' && !$this->tag->parent) { // TODO: hardcoded
			return;
		}

		if (!$end
			|| ($end->name !== $start->name && $end->name !== '')
			|| !$end->closing
			|| $end->void
		) {
			$tag = $end?->getNotation() ?? 'end';
			throw new CompileException("Unexpected $tag, expecting {/$start->name}", ($end ?? $start)->startLine);
		}
	}


	public function expectConsumed(Tag $tag): void
	{
		if (!$tag->parser->isEnd()) {
			$end = $tag->isNAttribute() ? ['end of attribute'] : ['end of tag'];
			$tag->parser->stream->throwUnexpectedException($end, addendum: ' in ' . $tag->getNotation());
		}
	}


	public function checkBlockIsUnique(Block $block): void
	{
		if ($block->isDynamic() || !preg_match('#^[a-z]#iD', $name = (string) $block->name->value)) {
			throw new CompileException(ucfirst($block->tag->name) . " name must start with letter a-z, '$name' given.", $block->tag->startLine);
		}

		if ($block->layer === Template::LayerSnippet
			? isset($this->blocks[$block->layer][$name])
			: (isset($this->blocks[Template::LayerLocal][$name]) || isset($this->blocks[$this->blockLayer][$name]))
		) {
			throw new CompileException("Cannot redeclare {$block->tag->name} '{$name}'", $block->tag->startLine);
		}

		$this->blocks[$block->layer][$name] = $block;
	}


	public function setPolicy(?Policy $policy): static
	{
		$this->policy = $policy;
		return $this;
	}


	public function setContentType(string $type): static
	{
		$this->contentType = $type;
		$this->lexer?->setContentType($type);
		return $this;
	}


	public function getContentType(): string
	{
		return $this->contentType;
	}


	/** @internal */
	public function getStream(): TokenStream
	{
		return $this->stream;
	}


	public function getTag(): ?Tag
	{
		return $this->tag;
	}


	public function getLexer(): TemplateLexer
	{
		return $this->lexer;
	}


	public function pushTag(Tag $tag): Tag
	{
		$tag->parent = $this->tag;
		$this->tag = $tag;
		return $tag;
	}


	public function popTag(): void
	{
		$this->tag = $this->tag->parent;
	}


	public function generateId(): int
	{
		return $this->counter++;
	}


	public function isTagAllowed(string $name): bool
	{
		return !$this->policy || $this->policy->isTagAllowed($name);
	}
}
