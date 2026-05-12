<?php

declare(strict_types=1);

namespace GruffPhp\Parser;

use GruffPhp\Source\SourceFile;
use PhpParser\Node\Stmt;
use PhpParser\Token;

/**
 * Carries a parsed source file, AST statements, tokens, and parse diagnostics.
 */
final readonly class AnalysisUnit
{
    /**
     * @param list<Stmt> $statements
     * @param list<Token> $tokens
     * @param list<ParseDiagnostic> $diagnostics
     */
    public function __construct(
        public SourceFile $file,
        public string $source,
        public array $statements,
        public array $tokens,
        public array $diagnostics,
    ) {
    }

    /**
     * Report whether parsing produced diagnostics for the source file.
     *
     * @return bool True when the unit has at least one parse diagnostic.
     */
    public function hasParseErrors(): bool
    {
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
            return 0;
        }

        return substr_count($this->source, "\n") + 1;
    }
}
