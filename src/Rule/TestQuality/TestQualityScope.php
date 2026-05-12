<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use PhpParser\Node;
use PhpParser\Node\Stmt;

final readonly class TestQualityScope
{
    /**
     * @param list<Stmt> $statements
     */
    public function __construct(
        public string $symbol,
        public string $name,
        public int $line,
        public ?int $endLine,
        public array $statements,
        public Node $node,
        public bool $isPest,
        public ?string $className = null,
    ) {
    }

    /**
     * Count the source lines covered by this test scope.
     *
     * @return int Inclusive line count for the test scope.
     */
    public function lineCount(): int
    {
        return $this->endLine === null ? 1 : max(1, $this->endLine - $this->line + 1);
    }
}
