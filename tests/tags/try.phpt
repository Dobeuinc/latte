<?php

/**
 * Test: {try} ... {else} {rollback} ... {/try}
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


function error()
{
	throw new Exception;
}


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);

// only try
Assert::match(
	<<<'XX'
		top begin
			in1 in2
		top end
		XX,
	$latte->renderToString(
		<<<'XX'
			top begin
			{try}
				in1 in2
			{/try}
			top end
			XX,
	),
);


// error
Assert::match(
	<<<'XX'
		top begin
		top end
		XX,
	$latte->renderToString(
		<<<'XX'
			top begin
			{try}
				in1 {=error()} in2
			{/try}
			top end
			XX,
	),
);


// catch
Assert::match(
	<<<'XX'
		top begin
			in1 in2
		top end
		XX,
	$latte->renderToString(
		<<<'XX'
			top begin
			{try}
				in1 in2
				{else}
				error
			{/try}
			top end
			XX,
	),
);


// catch + error
Assert::match(
	<<<'XX'
		top begin
			error
		top end
		XX,
	$latte->renderToString(
		<<<'XX'
			top begin
			{try}
				in1 {=error()} in2
				{else}
				error
			{/try}
			top end
			XX,
	),
);


// rollback
Assert::match(
	<<<'XX'
		top begin
		top end
		XX,
	$latte->renderToString(
		<<<'XX'
			top begin
			{try}
				in1 {rollback} in2
			{/try}
			top end
			XX,
	),
);


// rollback + catch
Assert::match(
	<<<'XX'
		top begin
			error
		top end
		XX,
	$latte->renderToString(
		<<<'XX'
			top begin
			{try}
				in1 {rollback} in2
				{else}
				error
			{/try}
			top end
			XX,
	),
);


// code
Assert::matchFile(
	__DIR__ . '/expected/try.phtml',
	$latte->compile(
		<<<'XX'
			{try}
				a
				{rollback}
				b
				{else}
				c
			{/try}
			XX,
	),
);


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
		Fragment:
			Try:
				Fragment:
					Text:
						content: '.try.'
					Rollback:
					Text:
						content: '.rollback.'
				Fragment:
					Text:
						content: '.else.'
	XX, exportTraversing('{try}.try.{rollback}.rollback.{else}.else.{/try}'));
