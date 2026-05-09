<?php

declare(strict_types=1);

namespace GruffPhp\Parser;

use GruffPhp\Source\SourceFile;
use PhpParser\Node\Stmt;
use PhpParser\Token;

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

    public function hasParseErrors(): bool
    {
        return $this->diagnostics !== [];
    }

    public function lineCount(): int
    {
        if ($this->source === '') {
            return 0;
        }

        return substr_count($this->source, "\n") + 1;
    }
}
