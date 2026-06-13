<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

class UnusedPrivateMethodFixture
{
    public function publicMethod(): int
    {
        return $this->usedPrivate();
    }

    public function sortRows(array $rows): array
    {
        usort($rows, [self::class, 'comparePromptRowsByLabel']);
        usort($rows, [UnusedPrivateMethodFixture::class, 'comparePromptRowsByScore']);
        $normalise = [$this, 'normalisePromptRow'];
        $format = self::formatPromptRow(...);
        $rows[] = $normalise($rows[0] ?? []);
        $rows[] = ['label' => $format($rows[0] ?? [])];

        return $rows;
    }

    private function usedPrivate(): int
    {
        return 42;
    }

    private static function comparePromptRowsByLabel(array $left, array $right): int
    {
        return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    }

    private static function comparePromptRowsByScore(array $left, array $right): int
    {
        return ((int) ($left['score'] ?? 0)) <=> ((int) ($right['score'] ?? 0));
    }

    private function normalisePromptRow(array $row): array
    {
        return $row;
    }

    private static function formatPromptRow(array $row): string
    {
        return (string) ($row['label'] ?? '');
    }

    private function unusedPrivate(): void
    {
        echo 'never called';
    }

    private function alsoUnused(): string
    {
        return 'dead';
    }

    protected function protectedMethod(): void
    {
    }

    private function __construct()
    {
    }

    public function __toString(): string
    {
        return '';
    }
}
