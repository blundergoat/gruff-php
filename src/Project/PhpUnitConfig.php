<?php

declare(strict_types=1);

namespace GruffPhp\Project;

use SimpleXMLElement;

final readonly class PhpUnitConfig
{
    public function __construct(
        public string $absolutePath,
        public string $displayPath,
        public SimpleXMLElement $root,
    ) {
    }
}
