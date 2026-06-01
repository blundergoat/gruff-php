<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Represents one test method scope discovered in an analysis unit.
 */
final readonly class TestQualityScope
{
    /**
     * @param string      $symbol - Stable display symbol for the discovered test scope.
     * @param string      $name - Test method or Pest description name.
     * @param int         $line - First source line of the test scope.
     * @param int|null    $endLine - Last source line of the test scope, when available.
     * @param list<Stmt>  $statements - Statements executed by the test scope.
     * @param Node        $node - AST node that owns the test scope.
     * @param bool        $isPest - Whether the scope came from a Pest test call.
     * @param string|null $className - Enclosing PHPUnit class name, when available.
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
     * @return int - Inclusive line count for the test scope.
     */
    public function lineCount(): int
    {
        // Unknown end line counts as a single line; otherwise the span is inclusive of both endpoints, never below 1.
        return $this->endLine === null ? 1 : max(1, $this->endLine - $this->line + 1);
    }
}
