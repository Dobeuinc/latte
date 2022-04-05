<?php

/**
 * Test: {switch}
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);

Assert::exception(
	fn() => $latte->compile('{case}'),
	Latte\CompileException::class,
	'Unexpected tag {case} (at column 1)',
);

Assert::exception(
	fn() => $latte->compile('{switch}{case}{/switch}'),
	Latte\CompileException::class,
	'Missing arguments in {case}',
);

Assert::exception(
	fn() => $latte->compile('{switch}{default 123}{/switch}'),
	Latte\CompileException::class,
	"Unexpected '123', expecting end of tag in {default} (at column 18)",
);

Assert::exception(
	fn() => $latte->compile('{switch}{default}{default}{/switch}'),
	Latte\CompileException::class,
	'Tag {switch} may only contain one {default} clause.',
);


$template = <<<'EOD'

	{switch 0}
	{case ''}string
	{default}def
	{case 0.0}flot
	{/switch}

	---

	{switch a}
	{case 1, 2, a}a
	{/switch}

	---

	{switch a}
	{default}def
	{/switch}

	---

	{switch a}
	{/switch}

	EOD;

Assert::matchFile(
	__DIR__ . '/expected/switch.phtml',
	$latte->compile($template),
);

Assert::match(
	<<<'X'

		def

		---

		a

		---

		def

		---

		X
,
	$latte->renderToString($template),
);


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
		Fragment:
			Switch:
				Integer:
					value: 3
				Array:
					ArrayItem:
						Integer:
							value: 1
					ArrayItem:
						Integer:
							value: 2
				Fragment:
					Text:
						content: '.case.'
				Fragment:
					Text:
						content: '.default.'
	XX, exportTraversing('{switch 3}  {case 1, 2}.case.{default}.default.{/switch}'));
