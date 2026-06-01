<?php

declare(strict_types=1);

namespace GruffPhp\Parser;

use GruffPhp\Source\SourceFile;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Token;

/**
 * Carries a parsed source file, AST statements, tokens, and parse diagnostics.
 *
 * The `statements`, `tokens`, and `source` properties are intentionally mutable
 * so the analysis pipeline can release each unit's heavy contents after the
 * per-unit rule phase, while the `file` / `diagnostics` shell stays around for
 * project-level rules and reporting metadata.
 */
final class AnalysisUnit
{
    /**
     * @param SourceFile            $file        Source file that produced this analysis unit.
     * @param string                $source      Raw source text.
     * @param list<Stmt>            $statements  Parsed top-level statements.
     * @param list<Token>           $tokens      Comment tokens emitted by the parser.
     * @param list<ParseDiagnostic> $diagnostics Parse diagnostics collected for the file.
     */
    public function __construct(
        public readonly SourceFile $file,
        public string $source,
        public array $statements,
        public array $tokens,
        public readonly array $diagnostics,
    ) {
    }

    /**
     * Report whether parsing produced diagnostics for the source file.
     *
     * @return bool True when the unit has at least one parse diagnostic.
     */
    public function hasParseErrors(): bool
    {
        // A non-empty diagnostics list is the recorded signal that parsing failed for this file.
        return $this->diagnostics !== [];
    }

    /**
     * Count source lines in the raw file contents.
     *
     * @return int Number of lines, or zero for an empty source string.
     */
    public function lineCount(): int
    {
        if ($this->source === '') {
            // An empty file has no lines; count zero rather than reporting a phantom first line.
            return 0;
        }

        // Add one because the final line carries no trailing newline, so newline count undercounts by one.
        return substr_count($this->source, "\n") + 1;
    }

    /**
     * Release the unit's parsed contents so the AST, token, and source memory
     * can be reclaimed. Project rules that have already extracted what they
     * need from this unit will not touch it again; the `file` and `diagnostics`
     * shell stays intact for reporting.
     *
     * @return void Unit is left in a released state with empty contents.
     */
    public function release(): void
    {
        // Break ParentConnectingVisitor's back-edges so PHP's reference
        // counter can free each node immediately, rather than waiting for the
        // cycle collector. This matters at scale (4-5GB peak on large-project
        // scans without it) because every AST node holds a `parent`
        // attribute pointing up the tree.
        foreach ($this->statements as $statement) {
            self::breakParentLinks($statement);
        }
        $this->source     = '';
        $this->statements = [];
        $this->tokens     = [];
    }

    /**
     * Recursively clear the `parent` attribute every node carries from
     * ParentConnectingVisitor so the AST is no longer a cycle.
     *
     * @param Node $node Subtree root to descend; its `parent` back-edge and every descendant's are nulled in place.
     * @return void
     */
    private static function breakParentLinks(Node $node): void
    {
        $node->setAttribute('parent', null);
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNodeValue = $node->{$subNodeName};
            if ($subNodeValue instanceof Node) {
                self::breakParentLinks($subNodeValue);
                continue;
            }
            if (is_array($subNodeValue)) {
                foreach ($subNodeValue as $item) {
                    if ($item instanceof Node) {
                        self::breakParentLinks($item);
                    }
                }
            }
        }
    }
}
