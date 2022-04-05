<?php

/**
 * Test: {embed block}
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader(templates: [
	'main' => <<<'XX'

				{embed embed1}
					{block a}
						{embed embed1}
							{block a}nested embeds A{/block}
						{/embed}
					{/block}
				{/embed}

				{define embed1}
				embed1-start
					{block a}embed1-A{/block}
				embed1-end
				{/define}

		XX,
]));

Assert::matchFile(
	__DIR__ . '/expected/embed.block.phtml',
	$latte->compile('main'),
);


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
		Fragment:
			Embed:
				String:
					value: foo
				Array:
					ArrayItem:
						Assign:
							Variable:
								name: var
							Integer:
								value: 10
				Fragment:
					Text:
						content: ' '
					Block:
						String:
							value: a
						Fragment:
					Text:
						content: ' '
	XX, exportTraversing('{embed foo, $var = 10} {block a/} {/embed}'));
