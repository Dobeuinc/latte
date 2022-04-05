<?php

/**
 * Test: {include ... with blocks}
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader([
	'main' => <<<'XX'

		{include (true ? "inc" : "") with blocks}

		{include test}

		XX,

	'inc' => <<<'XX'

		{define test}
			Parent: {basename($this->getReferringTemplate()->getName())}/{$this->getReferenceType()}
		{/define}

		XX,
]));

Assert::matchFile(
	__DIR__ . '/expected/include.with-blocks.phtml',
	$latte->compile('main'),
);
Assert::matchFile(
	__DIR__ . '/expected/include.with-blocks.html',
	$latte->renderToString('main'),
);
Assert::matchFile(
	__DIR__ . '/expected/include.with-blocks.inc.phtml',
	$latte->compile('inc'),
);


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
		Fragment:
			IncludeFile:
				String:
					value: file.latte
				Array:
				Filter:
					Identifier:
						name: trim
	XX, exportTraversing('{include file.latte with blocks|trim}'));
