<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

/**
 * One file picked up for analysis, tagged with how gruff should read it - as parsed PHP or as plain text.
 *
 * Discovery produces one of these per in-scope file. The type decides the path each file takes: PHP
 * files go through the AST parser so structural rules can inspect them, while other files (config, YAML,
 * lock files) are scanned as text by the rules that opt into text. The display path is what any finding
 * on the file shows the user.
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
     * @param string $displayPath - Project-relative path shown to the user in findings.
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
        // PHP-typed files take the AST parser path; every other type is scanned as plain text instead.
        return $this->type === self::TYPE_PHP;
    }
}
