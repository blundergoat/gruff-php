<?php

declare(strict_types=1);

namespace Fixtures\M08\Naming;

class MixedTestNamingFixture
{
    public function testItWorksCorrectly(): void {}
    public function test_it_handles_errors(): void {}
    public function testAnotherCase(): void {}
    public function test_edge_case(): void {}
}

class ConsistentTestNamingFixture
{
    public function testFirstCase(): void {}
    public function testSecondCase(): void {}
    public function testThirdCase(): void {}
}
