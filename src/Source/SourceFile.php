<?php

declare(strict_types=1);

namespace GruffPhp\Source;

/**
 * Describes one source file discovered for analysis.
 */
final readonly class SourceFile
{
    /**
     * Source type parsed through the PHP parser.
     */
    public const TYPE_PHP = 'php';

    /**
     * Source type scanned as plain text by source-text rules.
     */
    public const TYPE_TEXT = 'text';

    /**
     * Capture a discovered source file and the type gruff should apply to it.
     *
     * @param string $absolutePath Absolute filesystem path.
     * @param string $displayPath  Project-relative display path.
     * @param string $type         Source type used to choose parsing or text scanning.
     */
    public function __construct(
        public string $absolutePath,
        public string $displayPath,
        public string $type = self::TYPE_PHP,
    ) {
    }

    /**
     * Report whether the source file should be parsed as PHP.
     *
     * @return bool True when the file type is PHP.
     */
    public function isPhp(): bool
    {
        // PHP-typed files take the AST parser path; every other type is scanned as plain text instead.
        return $this->type === self::TYPE_PHP;
    }
}
