<?php

declare(strict_types=1);

namespace GruffPhp\Project;

use SimpleXMLElement;

/**
 * Carries the parsed PHPUnit configuration file and its source path.
 */
final readonly class PhpUnitConfig
{
    /**
     * Capture a discovered PHPUnit configuration file and loaded XML root.
     */
    public function __construct(
        public string $absolutePath,
        public string $displayPath,
        public SimpleXMLElement $root,
    ) {
    }
}
