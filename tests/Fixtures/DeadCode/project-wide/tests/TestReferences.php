<?php

declare(strict_types=1);

namespace App\Tests;

use App\TestOnlyClass;
use const App\TEST_ONLY_CONSTANT;
use function App\test_only_function;

final class FixtureTestCase
{
}

new TestOnlyClass();
$value = test_only_function() . TEST_ONLY_CONSTANT;
