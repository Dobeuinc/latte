<?php

/**
 * Test: {varType}
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);

Assert::exception(
	fn() => $latte->compile('{varType}'),
	Latte\CompileException::class,
	'Missing arguments in {varType}',
);

Assert::exception(
	fn() => $latte->compile('{varType type}'),
	Latte\CompileException::class,
	'Unexpected end, expecting variable (at column 10)',
);

Assert::exception(
	fn() => $latte->compile('{varType type var}'),
	Latte\CompileException::class,
	'Unexpected end, expecting variable (at column 15)',
);

Assert::exception(
	fn() => $latte->compile('{varType $var type}'),
	Latte\CompileException::class,
	"Unexpected 'type', expecting end of tag in {varType} (at column 15)",
);

Assert::noError(fn() => $latte->compile('{varType type $var}'));

Assert::noError(fn() => $latte->compile('{varType ?\Nm\Class $var}'));

Assert::noError(fn() => $latte->compile('{varType int|null $var}'));

Assert::noError(fn() => $latte->compile('{varType array{0: int, 1: int} $var}'));


// traversing
$latte = new Latte\Engine;
Assert::match(<<<'XX'
	Template:
		Fragment:
			VarType:
		Fragment:
	XX, exportTraversing('{varType int $int}'));
