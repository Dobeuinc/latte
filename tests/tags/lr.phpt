<?php

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);

Assert::match(
	"%A%echo '{ }';%A%",
	$latte->compile('{l} {r}'),
);


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
		Fragment:
			Text:
				content: '{'
			Text:
				content: ' '
			Text:
				content: '}'
	XX, exportTraversing('{l} {r}'));
