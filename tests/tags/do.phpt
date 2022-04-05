<?php

/**
 * Test: {do}
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);

Assert::match(
	'%A%$a = \'test\' ? [] : null%A%',
	$latte->compile('{do $a = test ? ([])}'),
);


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
			Do:
				Assign:
					Variable:
						name: a
					Integer:
						value: 1
		Fragment:
	XX, exportTraversing('{do $a = 1}'));
