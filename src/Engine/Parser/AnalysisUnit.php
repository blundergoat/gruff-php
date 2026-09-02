<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Parser;

use GruffPhp\Engine\Source\SourceFile;
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
     * Bundles a parsed file's source, statements, comment tokens, and any parse diagnostics.
     *
     * @param SourceFile            $file        - Source file that produced this analysis unit.
     * @param string                $source      - Raw source text.
     * @param list<Stmt>            $statements  - Parsed top-level statements.
     * @param list<Token>           $tokens      - Comment tokens emitted by the parser.
     * @param list<ParseDiagnostic> $diagnostics - Parse diagnostics collected for the file; empty when it parsed cleanly.
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
     * Reports whether this file hit fatal parse trouble, which is what marks it as unanalysed in reports.
     *
     * @return bool - True when the unit has at least one parse diagnostic.
     */
    public function hasParseErrors(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->isFatal) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether structural work was bounded while raw-text analysis remained available.
     *
     * @return bool - True once a bounded-deep-scan diagnostic was recorded against this unit.
     */
    public function isDeepScanBounded(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->type === 'bounded-deep-scan') {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts the source lines, used by size rules and reporting to describe how big the file is.
     *
     * @return int - Number of lines; zero for an empty source string.
     */
    public function lineCount(): int
    {
        // An empty file has no lines at all.
        if ($this->source === '') {
            // An empty file has no lines; count zero rather than reporting a phantom first line.
            return 0;
        }

        // Add one because the final line carries no trailing newline, so newline count undercounts by one.
        return substr_count($this->source, "\n") + 1;
    }

    /**
     * Releases the unit's heavy parsed contents so the AST, token, and source memory can be reclaimed
     * once per-file rules are done, keeping only the file/diagnostics shell that reporting still needs.
     *
     * Project rules that have already extracted what they
     * need from this unit will not touch it again; the `file` and `diagnostics`
     * shell stays intact for reporting.
     *
     * @return void - Unit is left in a released state with empty contents.
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
     * Recursively nulls the `parent` attribute every node carries from ParentConnectingVisitor, so the
     * AST stops being a reference cycle and PHP can free it promptly.
     *
     * @param Node $node - Subtree root to descend; its `parent` back-edge and every descendant's are nulled in
     *                   place.
     *
     * @return void
     */
    private static function breakParentLinks(Node $node): void
    {
        $node->setAttribute('parent', null);
        // Walk every child of this node, clearing parent links as we descend.
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNodeValue = $node->{$subNodeName};
            // A single child node: recurse straight into it.
            if ($subNodeValue instanceof Node) {
                self::breakParentLinks($subNodeValue);
                continue;
            }
            // A list of children: recurse into each node it holds.
            if (is_array($subNodeValue)) {
                // Descend into each node element of the child list.
                foreach ($subNodeValue as $item) {
                    // Only actual nodes carry the parent links we need to clear.
                    if ($item instanceof Node) {
                        self::breakParentLinks($item);
                    }
                }
            }
        }
    }
}
