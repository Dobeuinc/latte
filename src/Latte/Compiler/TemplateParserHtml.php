<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Compiler;

use Latte\CompileException;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\FragmentNode;
use Latte\Compiler\Nodes\Html;
use Latte\Context;
use Latte\Helpers;
use Latte\SecurityViolationException;
use Latte\Strict;


/**
 * Template parser extension for HTML.
 */
final class TemplateParserHtml
{
	use Strict;

	/** @var array<string, callable(Tag, TemplateParser): (Node|\Generator|void)> */
	private array $attrParsers = [];
	private ?Html\ElementNode $element = null;
	private TemplateParser $parser;


	public function __construct(TemplateParser $parser, array $attrParsers)
	{
		$this->parser = $parser;
		$this->attrParsers = array_reverse($attrParsers, true);
	}


	public function getElement(): ?Html\ElementNode
	{
		return $this->element;
	}


	public function inTextResolve(): ?Node
	{
		$stream = $this->parser->getStream();
		$token = $stream->current();
		return match ($token->type) {
			Token::Html_TagOpen => $stream->peek(1)->is(Token::Slash)
				? $this->parseEndTag()
				: $this->parseElement(),
			Token::Html_CommentOpen => $this->parseComment(),
			Token::Html_BogusOpen => $this->parseBogusTag(),
			default => $this->parser->inTextResolve(),
		};
	}


	public function inTagResolve(): ?Node
	{
		$token = $this->parser->getStream()->current();
		return match ($token->type) {
			Token::Html_Name => str_starts_with($token->text, TemplateLexer::NPrefix)
				? $this->parseNAttribute() // TODO: ne uvnitr bogusu
				: $this->parseAttribute(),
			Token::Quote => $this->parseAttributeQuote(),
			Token::Whitespace => $this->parseAttributeWhitespace(),
			Token::Html_TagClose => null,
			default => $this->parser->inTextResolve(),
		};
	}


	public function inAttrResolve(): ?Node
	{
		$token = $this->parser->getStream()->current();
		return match ($token->type) {
			Token::Quote => $this->parseAttributeQuote(),
			Token::Whitespace => $this->parseAttributeWhitespace(),
			default => $this->parser->inTextResolve(),
		};
	}


	private function parseElement(): Node
	{
		$res = new FragmentNode;
		$res->append($this->extractIndentation());
		$res->append($this->parseTag($this->element));
		$elem = $this->element;

		$stream = $this->parser->getStream();
		$void = $this->resolveVoidness($elem);
		$attrs = $this->prepareNAttributes($elem->nAttributes, $void);
		$outerNodes = $this->openNAttrNodes($attrs[Tag::PrefixNone] ?? [], $elem->attributes);
		$tagNodes = $this->openNAttrNodes($attrs[Tag::PrefixTag] ?? []);
		$elem->tagNode = $this->finishNAttrNodes($elem->tagNode, $tagNodes);
		$elem->captureTagName = (bool) $tagNodes;

		if (!$void) {
			$content = new FragmentNode;
			if ($token = $stream->tryConsume(Token::Newline)) {
				$content->append(new Nodes\TextNode($token->text, $token->line));
			}

			$innerNodes = $this->openNAttrNodes($attrs[Tag::PrefixInner] ?? []);
			$elem->data->tag = $this->parser->getTag();
			$frag = $this->parser->parseFragment([$this, 'inTextResolve']);
			$content->append($this->finishNAttrNodes($frag, $innerNodes));

			if ($this->isClosingTag($elem->name)) {
				$elem->content = $content;
				$elem->content->append($this->extractIndentation());
				$elem->endLine = $this->parseTag()->endLine;

			} elseif ($outerNodes || $innerNodes || $tagNodes) {
				$stream->throwUnexpectedException(
					addendum: ", expecting </{$elem->name}> for element started on line {$elem->startLine}",
				);
			} else { // element collapsed to tags
				$res->append($content);
				$this->element = $elem->parent;
				if ($this->element && !$stream->is(Token::Html_TagOpen)) {
					$this->element->data->unclosedTags[] = $elem->name;
				}
				return $res;
			}
		}

		if ($token = $stream->tryConsume(Token::Newline)) {
			$res->append(new Nodes\TextNode($token->text, $token->line));
		}

		$res = $this->finishNAttrNodes($res, $outerNodes);
		$this->element = $elem->parent;
		return $res;
	}


	private function extractIndentation(): AreaNode
	{
		if ($this->parser->lastIndentation) {
			$dolly = clone $this->parser->lastIndentation;
			$this->parser->lastIndentation->content = '';
			return $dolly;
		} else {
			return new Nodes\NopNode;
		}
	}


	private function parseTag(&$elem = null): Html\ElementNode
	{
		$stream = $this->parser->getStream();
		$openToken = $stream->consume(Token::Html_TagOpen);
		$stream->tryConsume(Token::Slash);
		$this->parser->lastIndentation = null;
		$this->parser->location = $this->parser::LocationTag;
		$elem = new Html\ElementNode(
			name: $stream->consume(Token::Html_Name)->text,
			startLine: $openToken->line,
			parent: $this->element,
			data: (object) ['tag' => $this->parser->getTag()],
		);
		$elem->attributes = $this->parser->parseFragment([$this, 'inTagResolve']);
		$elem->selfClosing = (bool) $stream->tryConsume(Token::Slash);
		$elem->endLine = $stream->consume(Token::Html_TagClose)->line;
		$this->parser->location = $this->parser::LocationText;
		return $elem;
	}


	private function parseEndTag(): ?Html\BogusTagNode
	{
		$stream = $this->parser->getStream();
		$name = $stream->peek(2)->text ?? '';

		if ($this->element
			&& $this->parser->getTag() === $this->element->data->tag
			&& (strcasecmp($name, $this->element->name) === 0
				|| !in_array($name, $this->element->data->unclosedTags ?? [], true))
		) {
			return null; // go back to parseElement()
		}

		$openToken = $stream->consume(Token::Html_TagOpen);
		$this->parser->lastIndentation = null;
		$this->parser->location = $this->parser::LocationTag;
		$node = new Html\BogusTagNode(
			openDelimiter: $openToken->text . $stream->consume(Token::Slash)->text . $stream->tryConsume(Token::Text)?->text,
			content: $this->parser->parseFragment([$this, 'inTagResolve']),
			endDelimiter: ($closeToken = $stream->consume(Token::Html_TagClose))->text,
			startLine: $openToken->line,
			endLine: $closeToken->line,
		);
		$this->parser->location = $this->parser::LocationText;
		return $node;
	}


	private function parseBogusTag(): Html\BogusTagNode
	{
		$stream = $this->parser->getStream();
		$openToken = $stream->consume(Token::Html_BogusOpen);
		$this->parser->lastIndentation = null;
		$this->parser->location = $this->parser::LocationTag;
		$content = $this->parser->parseFragment(fn() => match ($stream->current()->type) {
			Token::Html_TagClose => null,
			default => $this->parser->inTextResolve(),
		});
		$this->parser->location = $this->parser::LocationText;
		return new Html\BogusTagNode(
			openDelimiter: $openToken->text,
			content: $content,
			endDelimiter: ($closeToken = $stream->consume(Token::Html_TagClose))->text,
			startLine: $openToken->line,
			endLine: $closeToken->line,
		);
	}


	private function resolveVoidness(Html\ElementNode $elem): bool
	{
		if ($this->parser->getContentType() !== Context::Html) {
			return $elem->selfClosing;
		} elseif (isset(Helpers::$emptyElements[strtolower($elem->name)])) {
			return true;
		} elseif ($elem->selfClosing) { // auto-correct
			$elem->content = new Nodes\NopNode;
			$elem->selfClosing = false;
			$last = end($elem->attributes->children);
			if ($last instanceof Nodes\TextNode && $last->isWhitespace()) {
				array_pop($elem->attributes->children);
			}
			return true;
		}

		return $elem->selfClosing;
	}


	private function parseAttributeWhitespace(): Node
	{
		$stream = $this->parser->getStream();
		$token = $stream->consume(Token::Whitespace);
		return $stream->is(Token::Html_Name) && str_starts_with($stream->current()->text, TemplateLexer::NPrefix)
			? new Nodes\NopNode
			: new Nodes\TextNode($token->text, $token->line);
	}


	private function parseAttribute(): Node
	{
		$stream = $this->parser->getStream();
		$nameToken = $stream->consume(Token::Html_Name);
		$save = $stream->getIndex();
		$this->consumeIgnored();

		$value = null;
		if ($stream->tryConsume(Token::Equals)) {
			$this->consumeIgnored();
			if ($stream->is(Token::Quote)) {
				$value = $this->parseAttributeQuote();
			} elseif ($stream->is(Token::Latte_TagOpen) && !$stream->peek(1)->is(Token::Slash)) {
				$value = $this->parser->parseFragment([$this, 'inAttrResolve']); // TODO: limit to first item
			} elseif ($token = $stream->tryConsume(Token::Html_Name)) {
				$value = new Nodes\TextNode($token->text, $token->line);
			} else {
				$stream->throwUnexpectedException();
			}
		} else {
			$stream->seek($save);
		}

		return new Html\AttributeNode(
			name: $nameToken->text,
			value: $value,
			startLine: $nameToken->line,
		);
	}


	private function parseNAttribute(): Nodes\TextNode
	{
		$stream = $this->parser->getStream();
		$nameToken = $stream->consume(Token::Html_Name);
		$save = $stream->getIndex();
		$name = substr($nameToken->text, strlen(TemplateLexer::NPrefix));
		if ($this->parser->getTag() !== $this->element->data->tag) {
			throw new CompileException("Attribute n:$name must not appear inside {tags}", $nameToken->line, $nameToken->column);

		} elseif (isset($this->element->nAttributes[$name])) {
			throw new CompileException("Found multiple attributes n:$name.", $nameToken->line, $nameToken->column);
		}

		$this->consumeIgnored();
		if ($stream->tryConsume(Token::Equals)) {
			$this->consumeIgnored();
			if ($stream->tryConsume(Token::Quote)) {
				$valueToken = $stream->tryConsume(Token::Text);
				$stream->consume(Token::Quote);
			} else {
				$valueToken = $stream->consume(Token::Html_Name);
			}
			$tokens = $valueToken
				? (new TagLexer)->tokenize($valueToken->text, $valueToken->line, $valueToken->column)
				: [];
		} else {
			$tokens = [];
			$stream->seek($save);
		}

		$this->element->nAttributes[$name] = new Tag(
			name: preg_replace('~(inner-|tag-|)~', '', $name),
			tokens: $tokens,
			startLine: $nameToken->line,
			prefix: match (true) {
				str_starts_with($name, 'inner-') => Tag::PrefixInner,
				str_starts_with($name, 'tag-') => Tag::PrefixTag,
				default => Tag::PrefixNone,
			},
			location: $this->parser->location,
			htmlElement: $this->element,
			data: (object) ['node' => $node = new Nodes\TextNode('')], // TODO: better
		);
		return $node;
	}


	private function parseAttributeQuote(): Html\QuotedValue
	{
		$stream = $this->parser->getStream();
		$quoteToken = $stream->consume(Token::Quote);
		$value = $this->parser->parseFragment(fn() => match ($stream->current()->type) {
			Token::Quote => null,
			default => $this->parser->inTextResolve(),
		});
		return new Html\QuotedValue(
			value: $value,
			quote: $quoteToken->text,
			startLine: $quoteToken->line,
			endLine: $stream->consume(Token::Quote)->line,
		);
	}


	private function parseComment(): Html\CommentNode
	{
		$this->parser->lastIndentation = null;
		$this->parser->location = $this->parser::LocationTag;
		$stream = $this->parser->getStream();
		$node = new Html\CommentNode(
			startLine: $stream->consume(Token::Html_CommentOpen)->line,
			content: $this->parser->parseFragment(fn() => match ($stream->current()->type) {
				Token::Html_CommentClose => null,
				default => $this->parser->inTextResolve(),
			}),
			endLine: $stream->consume(Token::Html_CommentClose)->line,
		);
		$this->parser->location = $this->parser::LocationText;
		return $node;
	}


	private function consumeIgnored(): void
	{
		$stream = $this->parser->getStream();
		do {
			if ($stream->tryConsume(Token::Whitespace)) {
				continue;
			}
			if ($stream->tryConsume(Token::Latte_CommentOpen)) {
				$stream->consume(Token::Text);
				$stream->consume(Token::Latte_CommentClose);
				$stream->tryConsume(Token::Newline);
				continue;
			}
			return;
		} while (true);
	}


	private function isClosingTag(string $name): bool
	{
		$stream = $this->parser->getStream();
		return $stream->is(Token::Html_TagOpen)
			&& $stream->peek(1)->is(Token::Slash)
			&& strcasecmp($name, $stream->peek(2)->text ?? '') === 0;
	}


	private function prepareNAttributes(array $attrs, bool $void): array
	{
		$res = [];
		foreach ($this->attrParsers as $name => $parser) {
			if ($tag = $attrs[$name] ?? null) {
				$prefix = substr($name, 0, (int) strpos($name, '-'));
				if (!$prefix || !$void) {
					$res[$prefix][] = $tag;
					unset($attrs[$name]);
				}
			}
		}

		if ($attrs) {
			$hint = Helpers::getSuggestion(array_keys($this->attrParsers), $k = key($attrs));
			throw new CompileException('Unexpected attribute n:'
				. ($hint ? "$k, did you mean n:$hint?" : implode(' and n:', array_keys($attrs))), $this->element->startLine);
		}

		return $res;
	}


	/**
	 * @param  array<Tag>  $toOpen
	 * @return array<array{\Generator, Tag}>
	 */
	private function openNAttrNodes(array $toOpen, ?FragmentNode $attributes = null): array
	{
		$toClose = [];
		foreach ($toOpen as $tag) {
			$parser = $this->getTagParser($tag->name, $tag->startLine);
			$this->parser->pushTag($tag);
			$res = $parser($tag, $this->parser);
			if ($res instanceof \Generator && $res->valid()) {
				$toClose[] = [$res, $tag];

			} elseif ($res instanceof Node) {
				$this->parser->expectConsumed($tag);
				$res->startLine = $res->endLine = $tag->startLine;
				$tag->replaceNAttribute($res);
				$this->parser->popTag();

			} elseif (!$res) {
				$this->parser->expectConsumed($tag);
				$this->parser->popTag();

			} else {
				throw new CompileException("Unexpected value returned by {$tag->getNotation()} parser.", $tag->startLine);
			}
		}

		return $toClose;
	}


	/** @param  array<array{\Generator, Tag}>  $toClose */
	private function finishNAttrNodes(AreaNode $node, array $toClose): AreaNode
	{
		while ([$gen, $tag] = array_pop($toClose)) {
			$gen->send([$node, null]);
			$node = $gen->getReturn();
			$node->startLine = $node->endLine = $tag->startLine;
			$this->parser->popTag();
			$this->parser->expectConsumed($tag);
		}

		return $node;
	}


	/** @return callable(Tag, TemplateParser): (Node|\Generator|void) */
	private function getTagParser(string $name, int $line): callable
	{
		if (!isset($this->attrParsers[$name])) {
			$hint = ($t = Helpers::getSuggestion(array_keys($this->attrParsers), $name))
				? ", did you mean n:$t?"
				: '';
			throw new CompileException("Unknown n:{$name}{$hint}", $line);
		} elseif (!$this->parser->isTagAllowed($name)) {
			throw new SecurityViolationException("Attribute n:$name is not allowed.");
		}
		return $this->attrParsers[$name];
	}
}
