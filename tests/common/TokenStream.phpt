<?php

declare(strict_types=1);

use Latte\CompileException;
use Latte\Compiler\Token;
use Latte\Compiler\TokenStream;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('empty', function () {
	$stream = new TokenStream(new ArrayIterator([]));
	Assert::null($stream->current());
	Assert::false($stream->is());
	Assert::null($stream->peek(0));
	Assert::null($stream->tryConsume());
	Assert::same(0, $stream->getIndex());
});


test('current()', function () {
	$token = new Token(0, '');
	$stream = new TokenStream(new ArrayIterator([$token]));

	Assert::same($token, $stream->current());
	Assert::same($token, $stream->tryConsume());
	Assert::null($stream->current());
});


test('is()', function () {
	$token = new Token(Token::Text, 'foo');
	$stream = new TokenStream(new ArrayIterator([$token]));

	Assert::false($stream->is());
	Assert::true($stream->is('foo'));
	Assert::false($stream->is(''));
	Assert::true($stream->is('', 'foo'));
	Assert::true($stream->is(Token::Text));

	Assert::same($token, $stream->tryConsume());
	Assert::false($stream->is('foo'));
});


test('peek()', function () {
	$token1 = new Token(0, '');
	$token2 = new Token(0, '');
	$stream = new TokenStream(new ArrayIterator([$token1, $token2]));

	Assert::null($stream->peek(-1));
	Assert::same(0, $stream->getIndex());
	Assert::same($token1, $stream->peek(0));
	Assert::same($token2, $stream->peek(1));
	Assert::null($stream->peek(2));
	Assert::same(0, $stream->getIndex());

	$stream->consume();
	Assert::null($stream->peek(-2));
	Assert::same($token1, $stream->peek(-1));
	Assert::same($token2, $stream->peek(0));
	Assert::null($stream->peek(1));

	$stream->consume();
	Assert::null($stream->peek(-3));
	Assert::same($token1, $stream->peek(-2));
	Assert::same($token2, $stream->peek(-1));
	Assert::null($stream->peek(0));
});


test('peek() jump forward', function () {
	$token1 = new Token(0, '');
	$token2 = new Token(0, '');
	$token3 = new Token(0, '');
	$stream = new TokenStream(new ArrayIterator([$token1, $token2, $token3]));

	Assert::same($token3, $stream->peek(2));
});


test('consume() any token', function () {
	$token = new Token(0, '', 123);
	$stream = new TokenStream(new ArrayIterator([$token]));

	Assert::same($token, $stream->consume());
	Assert::same(1, $stream->getIndex());
	Assert::exception(
		fn() => $stream->consume(),
		CompileException::class,
		'Unexpected end (on line 123)',
	);
	Assert::same(1, $stream->getIndex());
});


test('consume() kind of token', function () {
	$token = new Token(Token::Text, 'foo');
	$stream = new TokenStream(new ArrayIterator([$token]));

	Assert::exception(
		fn() => $stream->consume('bar'),
		CompileException::class,
		"Unexpected 'foo', expecting 'bar'",
	);
	Assert::same(0, $stream->getIndex());
	Assert::same($token, $stream->consume('foo'));
	Assert::same(1, $stream->getIndex());
});


test('tryConsume() any token', function () {
	$token = new Token(0, '');
	$stream = new TokenStream(new ArrayIterator([$token]));

	Assert::same($token, $stream->tryConsume());
	Assert::same(1, $stream->getIndex());
	Assert::null($stream->tryConsume());
	Assert::same(1, $stream->getIndex());
});


test('tryConsume() kind of token', function () {
	$token = new Token(Token::Text, 'foo');
	$stream = new TokenStream(new ArrayIterator([$token]));

	Assert::null($stream->tryConsume('bar'));
	Assert::same(0, $stream->getIndex());
	Assert::same($token, $stream->tryConsume('foo'));
	Assert::same(1, $stream->getIndex());
});


test('seek()', function () {
	$token = new Token(0, '');
	$stream = new TokenStream(new ArrayIterator([$token]));

	Assert::noError(fn() => $stream->seek(0));
	Assert::exception(
		fn() => $stream->seek(1),
		InvalidArgumentException::class,
		'The position is out of range.',
	);
	$stream->consume();
	Assert::noError(fn() => $stream->seek(1));
	Assert::exception(
		fn() => $stream->seek(-1),
		InvalidArgumentException::class,
		'The position is out of range.',
	);
});


test('generator is read on the first usage', function () {
	$generator = function () {
		throw new Exception('Generator');
		yield null;
	};
	$stream = new TokenStream($generator());
	Assert::exception(
		fn() => $stream->current(),
		Throwable::class,
		'Generator',
	);
});


test('generator is read continually', function () {
	$generator = function () {
		yield new Token(0, '');
		throw new Exception('Generator');
	};
	$stream = new TokenStream($generator());
	$stream->consume();
	Assert::exception(
		fn() => $stream->current(),
		Throwable::class,
		'Generator',
	);
});


test('hidden & current', function () {
	$tokens = [
		new Token(Token::Equals, '='),
		new Token(Token::Text, 'a'),
		new Token(Token::Whitespace, 'xx'),
		new Token(Token::Text, 'c'),
	];
	$stream = new TokenStream(new ArrayIterator($tokens), [Token::Equals, Token::Whitespace]);

	Assert::same(0, $stream->getIndex());
	Assert::same($tokens[1], $stream->current());
	Assert::same(1, $stream->getIndex());
	$stream->consume();
	Assert::same(2, $stream->getIndex());
	Assert::same($tokens[3], $stream->current());
	Assert::same(3, $stream->getIndex());
});


test('hidden & peek', function () {
	$tokens = [
		new Token(Token::Equals, '='),
		new Token(Token::Text, 'a'),
		new Token(Token::Whitespace, 'xx'),
		new Token(Token::Text, 'c'),
	];
	$stream = new TokenStream(new ArrayIterator($tokens), [Token::Equals, Token::Whitespace]);

	Assert::same($tokens[0], $stream->peek(0));
	Assert::same($tokens[2], $stream->peek(2));
	Assert::same(0, $stream->getIndex());
});


test('hidden & getText', function () {
	$tokens = [
		new Token(Token::Equals, '='),
		new Token(Token::Text, 'a'),
		new Token(Token::Whitespace, 'xx'),
		new Token(Token::Text, 'c'),
	];
	$stream = new TokenStream(new ArrayIterator($tokens), [Token::Equals, Token::Whitespace]);

	Assert::same('=axxc', $stream->getText());
	Assert::same(0, $stream->getIndex());
	Assert::same('axxc', $stream->getText(1));
	Assert::same('axx', $stream->getText(1, 2));
	$stream->consume();
	Assert::same('axx', $stream->getText(1, 2));
});


test('hidden & getText & advance', function () {
	$tokens = [
		new Token(Token::Equals, '='),
		new Token(Token::Text, 'a'),
		new Token(Token::Whitespace, 'xx'),
		new Token(Token::Text, 'c'),
	];
	$stream = new TokenStream(new ArrayIterator($tokens), [Token::Equals, Token::Whitespace]);

	Assert::same('=axxc', $stream->getText(advance: true));
	Assert::same(4, $stream->getIndex());
});
