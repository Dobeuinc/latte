<?php

/**
 * Test: {php}
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);

Assert::match(
	'%A%$a = \'test\' ? [] : null%A%',
	$latte->compile('{php $a = test ? ([])}'),
);


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
			Do:
				Variable:
					name: var
		Fragment:
	XX, exportTraversing('{php $var}'));
