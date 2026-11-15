<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

/**
 * Describes a source file's filesystem path, display path, and parser selection.
 * PHP selects AST parsing; other types retain source text without AST or token processing.
 * Discovery uses root-relative display paths where possible and canonical absolute paths otherwise.
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
     * Captures a discovered file and the type that decides how gruff reads it.
     *
     * @param string $absolutePath - Absolute filesystem path to the file.
     * @param string $displayPath - Path shown in findings, which may be root-relative or absolute.
     * @param string $type - Source type that selects PHP parsing or plain-text scanning; defaults to PHP.
     */
    public function __construct(
        public string $absolutePath,
        public string $displayPath,
        public string $type = self::TYPE_PHP,
    ) {
    }

    /**
     * Reports whether this file should go through the PHP parser rather than be scanned as text.
     *
     * @return bool - True when the file is PHP-typed and takes the AST parser path.
     */
    public function isPhp(): bool
    {

        return $this->type === self::TYPE_PHP;
    }
}
