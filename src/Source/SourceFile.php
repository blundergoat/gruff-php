<?php

declare(strict_types=1);

namespace GruffPhp\Source;

final readonly class SourceFile
{
    public const TYPE_PHP = 'php';
    public const TYPE_TEXT = 'text';

    /**
     * Capture a discovered source file and the type gruff should apply to it.
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
        return $this->type === self::TYPE_PHP;
    }
}
