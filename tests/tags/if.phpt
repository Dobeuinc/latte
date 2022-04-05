<?php

/**
 * Test: {if}
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);

$template = <<<'EOD'

	{if true}
		a
		{elseif $b}
		b
		{elseifset $c}
		c
		{else}
		d
	{/if}

	--

	{if}
		a
	{/if true}

	--

	{if}
		a
		{else}
		d
	{/if true}

	--

	{ifset $a}
		a
		{elseif $b}
		b
		{elseifset $c}
		c
		{else}
		d
	{/ifset}

	EOD;

Assert::matchFile(
	__DIR__ . '/expected/if.phtml',
	$latte->compile($template),
);



// breaking
$template = <<<'X'
	{foreach [1, 0] as $cond}
		{$cond}
		{if}
			if
			{else}
			else
			{continueIf $cond}
			breaked
		{/if true}
		end
	{/foreach}
	X;

Assert::match(
	<<<'XX'
			1
			0
				if
			end
		XX,
	$latte->renderToString($template),
);


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
		Fragment:
			If:
				Variable:
					name: a
				Fragment:
					Text:
						content: '.if.'
				If:
					Variable:
						name: b
					Fragment:
						Text:
							content: '.elseif.'
					Fragment:
						Text:
							content: '.else.'
	XX, exportTraversing('{if $a}.if.{elseif $b}.elseif.{else}.else.{/if}'));
