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
     * @param string      $symbol - Stable display symbol for the discovered test scope.
     * @param string      $name - Test method or Pest description name.
     * @param int         $line - First source line of the test scope.
     * @param int|null    $endLine - Last source line of the test scope, or null when the parser could not locate the scope's end.
     * @param list<Stmt>  $statements - Statements executed by the test scope.
     * @param Node        $node - AST node that owns the test scope.
     * @param bool        $isPest - Whether the scope came from a Pest test call.
     * @param string|null $className - Enclosing PHPUnit class name, or null for a Pest or top-level scope with no class.
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
     * Returns the line a finding on this scope should report.
     *
     * `$line` is where the scope's span starts, attribute groups included, because the span is what the length
     * and range rules measure. A PHP 8 attribute is a sub-node of the method it decorates, so reporting that
     * span start points the reader at `#[DataProvider(...)]` instead of at the test. The declaration itself is
     * what a finding names.
     *
     * @return int - 1-based source line of the declaration, never the line of one of its attributes.
     */
    public function anchorLine(): int
    {
        // Only a method scope can carry attributes; a Pest closure's span already starts at its test() call.
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
