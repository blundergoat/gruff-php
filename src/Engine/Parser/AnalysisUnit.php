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
 * Mutable `statements`, `tokens`, and `source` let the pipeline release parsed contents after their consumers finish.
 * Readonly `file` and `diagnostics` remain available for project-level rules and reporting.
 */
final class AnalysisUnit
{
    /**
     * Bundles a file's source, statements, retained parser tokens, and diagnostics.
     *
     * @param SourceFile            $file        - Source file that produced this analysis unit.
     * @param string                $source      - Raw source text.
     * @param list<Stmt>            $statements  - Parsed top-level statements.
     * @param list<Token>           $tokens      - Parser tokens retained for analysis.
     * @param list<ParseDiagnostic> $diagnostics - Parse failures or nonfatal limit notices; empty when none were recorded.
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
     * @return bool - True when at least one diagnostic is fatal; false for an empty list or nonfatal notices only.
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
     * @return int - Newline-delimited segment count, including a trailing empty segment; zero for empty source.
     */
    public function lineCount(): int
    {

        if ($this->source === '') {
            // An empty file has no lines; count zero rather than reporting a phantom first line.
            return 0;
        }


        return substr_count($this->source, "\n") + 1;
    }

    /**
     * Clears source, statements, and tokens after their consumers finish, keeping file metadata and diagnostics for reporting.
     *
     * @return void - Unit is left in a released state with empty contents.
     */
    public function release(): void
    {
        // Remove parent-child cycles before dropping the statements to avoid relying on PHP's cycle collector.




        foreach ($this->statements as $statement) {
            self::breakParentLinks($statement);
        }
        $this->source     = '';
        $this->statements = [];
        $this->tokens     = [];
    }

    /**
     * Clears parent attributes throughout a subtree to break the AST's parent-child reference cycles.
     *
     * @param Node $node - Root of the subtree whose parent links are cleared in place.
     *
     * @return void - Parent attributes are null throughout the subtree.
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
