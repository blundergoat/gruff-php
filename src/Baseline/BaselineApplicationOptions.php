<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

/**
 * Carries baseline mode flags used while applying or writing baselines.
 */
final readonly class BaselineApplicationOptions
{
    /**
     * Capture the effective baseline flags selected for an analysis run.
     */
    public function __construct(
        public ?string $baselinePath,
        public bool $baselineExplicit,
        public ?string $generateBaselinePath,
    ) {
    }
}
