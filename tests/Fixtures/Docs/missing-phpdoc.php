<?php

declare(strict_types=1);

namespace Fixtures\Docs;

class MissingPhpdocFixture
{
    public function undocumented(int $x): int
    {
        if ($x > 10) {
            return $x * 2;
        }

        return $x + 1;
    }

    public function trivialUndocumented(int $x): int
    {
        return $x * 2;
    }

    /**
     * Documented method.
     */
    public function documented(): void
    {
    }

    public function getTitle(): string
    {
        return '';
    }

    public function setTitle(string $title): void
    {
    }

    public function isActive(): bool
    {
        return true;
    }

    private function privateMethod(): void
    {
    }

    protected function protectedMethod(): void
    {
    }

    public function __toString(): string
    {
        return '';
    }
}

class RuleContractFixture implements \GruffPhp\Rule\RuleInterface
{
    public function definition(): \GruffPhp\Rule\RuleDefinition
    {
        if (rand(0, 1) === 1) {
            throw new \RuntimeException('not executed');
        }

        throw new \RuntimeException('not executed');
    }

    public function analyse(\GruffPhp\Parser\AnalysisUnit $unit, \GruffPhp\Rule\RuleContext $context): array
    {
        if ($unit->lineCount() > 0 && $context->projectRoot !== '') {
            return [];
        }

        return [];
    }
}

class InternalHelper
{
    public function complexUtility(int $x): int
    {
        if ($x > 0) {
            return $x;
        }

        return 0;
    }
}

class TextReporter
{
    public function render(string $text): string
    {
        if ($text !== '') {
            return $text;
        }

        return 'empty';
    }
}

abstract class AbstractFixture
{
    abstract protected function inheritedHook(): void;
}
