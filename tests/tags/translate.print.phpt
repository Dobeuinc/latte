<?php

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$latte = new Latte\Engine;
$latte->setLoader(new Latte\Loaders\StringLoader);

Assert::contains(
	'echo LR\Filters::escapeHtmlText(($this->filters->translate)(\'var\')) /*',
	$latte->compile('{_var}'),
);

Assert::contains(
	'echo LR\Filters::escapeHtmlText(($this->filters->filter)(($this->filters->translate)(\'var\'))) /*',
	$latte->compile('{_var|filter}'),
);


// traversing
Assert::match(<<<'XX'
	Template:
		Fragment:
		Fragment:
			Print:
				Variable:
					name: var
				Filter:
					Filter:
						Identifier:
							name: translate
					Identifier:
						name: trim
	XX, exportTraversing('{_$var|trim}'));
