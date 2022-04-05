<?php

/**
 * Test: {debugbreak}
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);

if (!function_exists('xdebug_break')) {
	function xdebug_break()
	{
	}
}

Assert::match('%A%xdebug_break()%A%', $latte->compile('{debugbreak}'));

Assert::match('%A%if ($i == 1) xdebug_break()%A%', $latte->compile('{debugbreak $i==1}'));


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
			Debugbreak:
				BinaryOp:
					operator: ==
					Variable:
						name: i
					Integer:
						value: 1
		Fragment:
	XX, exportTraversing('{debugbreak $i==1}'));
