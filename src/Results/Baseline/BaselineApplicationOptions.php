<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * Carries baseline mode flags used while applying or writing baselines.
 */
final readonly class BaselineApplicationOptions
{
    /**
     * Capture the effective baseline flags selected for an analysis run.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param string|null $baselinePath - Baseline file path to apply, when enabled.
     * @param bool        $isBaselineExplicit - Whether the baseline path came from an explicit flag.
     * @param string|null $generateBaselinePath - Baseline file path to write, when generation is enabled.
     */
    public function __construct(
        public ?string $baselinePath,
        public bool $isBaselineExplicit,
        public ?string $generateBaselinePath,
    ) {
    }
}
