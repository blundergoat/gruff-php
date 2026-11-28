<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Support\DeclarationLine;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * One discovered test scope - a PHPUnit test method or a Pest test() closure - with the metadata the
 * TestQuality rules need to reason about it: its display symbol, name, line span, statements, and
 * enclosing class. Built by TestQualityNodeHelper and passed read-only to every test-quality rule.
 */
final readonly class TestQualityScope
{
    /**
     * Stores the metadata and AST node for a discovered test scope.
     *
     * @param string $symbol - Stable display symbol for the discovered test scope.
     * @param string $name - Test method or Pest description name.
     * @param int $line - First source line of the test scope.
     * @param int|null $endLine - Last scope line, or null to make lineCount() use one line.
     * @param list<Stmt> $statements - Top-level AST statements forming the test body.
     * @param Node $node - AST node that owns the test scope.
     * @param bool $isPest - Whether the scope came from a Pest test call.
     * @param string|null $className - Named enclosing class, or null for Pest scopes, anonymous classes, or missing class context.
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
     * Returns the source line used to anchor a finding on this scope.
     * Methods use DeclarationLine::of(); other scopes retain their stored start line.
     *
     * @return int - Method declaration anchor or stored scope start; an inline attribute can share the returned line.
     */
    public function anchorLine(): int
    {

        return $this->node instanceof Stmt\ClassMethod ? DeclarationLine::of($this->node) : $this->line;
    }

    /**
     * Returns the source-line span this test scope covers.
     *
     * @return int - Inclusive line count for the test scope.
     */
    public function lineCount(): int
    {
        // Unknown end line counts as a single line; otherwise the span is inclusive of both endpoints, never below 1.
        return $this->endLine === null ? 1 : max(1, $this->endLine - $this->line + 1);
    }
}
