<?php

/**
 * Test: {define ...}
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);

$template = <<<'XX'
	{var $var = 10}

	{define test}
		This is definition #{$var}
	{/define}

	{include #test, var => 20}

	{define true}true{/define}
	{include true}
	XX;

Assert::matchFile(
	__DIR__ . '/expected/define.phtml',
	$latte->compile($template),
);
Assert::matchFile(
	__DIR__ . '/expected/define.html',
	$latte->renderToString($template),
);


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
		Fragment:
			Define:
				String:
					value: test
				Fragment:
					Text:
						content: '...'
	XX, exportTraversing('{define test, $a, $b = new Foo}...{/define}'));
