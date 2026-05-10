<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

final readonly class BaselineApplicationOptions
{
    public function __construct(
        public ?string $baselinePath,
        public bool $baselineExplicit,
        public ?string $generateBaselinePath,
    ) {
    }
}
