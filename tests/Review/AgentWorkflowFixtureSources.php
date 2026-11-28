<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

/**
 * Provides PHP source strings for branch-review CLI fixtures.
 * The Example variants preserve or add undocumented methods for finding comparisons.
 * The NewRisk variant supplies an unserialize() call without an object restriction.
 */
final class AgentWorkflowFixtureSources
{
    /**
     * Return source code for the base review fixture.
     *
     * @return string - PHP source for Example with an undocumented calculate() method retained in the changed fixture.
     */
    public static function baseExampleSource(): string
    {
        return <<<'PHP'
<?php

/**
 * Covers Example behavior.
 */
final class Example
{
    public function calculate(string $left, string $right): string
    {
        return $left . $right;
    }
}
PHP;
    }

    /**
     * Return source code for the changed review fixture.
     *
     * @return string - PHP source for the Example class with an added newRisk() method, landing in changed-only diff scope
     */
    public static function changedExampleSource(): string
    {
        return <<<'PHP'
<?php



/**
 * Covers Example behavior.
 */
final class Example
{
    public function calculate(string $left, string $right): string
    {
        return $left . $right;
    }

    public function newRisk(string $left, string $right): string
    {
        return $left . $right;
    }
}
PHP;
    }

    /**
     * Return source code for the removed-base review fixture.
     *
     * @return string - PHP source for the Example class carrying oldRisk(), present only on the base side so it reads as a removed finding
     */
    public static function removedBaseExampleSource(): string
    {
        return <<<'PHP'
<?php

/**
 * Covers Example behavior.
 */
final class Example
{
    public function calculate(string $left, string $right): string
    {
        return $left . $right;
    }

    public function oldRisk(string $left, string $right): string
    {
        return $left . $right;
    }
}
PHP;
    }

    /**
     * Return source code for an added risky review fixture.
     *
     * @return string - PHP source with an unrestricted unserialize() call, reported when security.unsafe-unserialize is enabled.
     */
    public static function addedRiskSource(): string
    {
        return <<<'PHP'
<?php

/**
 * Covers NewRisk behavior.
 */
final class NewRisk
{
    public function decode(string $payload): mixed
    {
        return unserialize($payload);
    }
}
PHP;
    }

}
