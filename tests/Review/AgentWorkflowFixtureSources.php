<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

/**
 * Provides PHP source strings for branch-review CLI fixtures.
 */
final class AgentWorkflowFixtureSources
{
    /**
     * Return source code for the base review fixture.
     *
     * @return string - PHP source for the clean Example class used as the "before" side of a branch-review diff
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
     * @return string - PHP source for the NewRisk class whose unserialize() of caller input trips a real security rule
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
