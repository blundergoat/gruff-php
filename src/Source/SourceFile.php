<?php

declare(strict_types=1);

namespace GruffPhp\Source;

final readonly class SourceFile
{
    public const TYPE_PHP = 'php';
    public const TYPE_TEXT = 'text';

    public function __construct(
        public string $absolutePath,
        public string $displayPath,
        public string $type = self::TYPE_PHP,
    ) {
    }

    public function isPhp(): bool
    {
        return $this->type === self::TYPE_PHP;
    }
}
