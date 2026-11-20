<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * Immutable baseline paths and overwrite choices for one analysis run.
 *
 * The command layer supplies these options to `BaselineApplication`.
 * Generation takes precedence over application; migration is considered only during generation.
 */
final readonly class BaselineApplicationOptions
{
    /**
     * Captures the baseline choices for one analyse run.
     *
     * @param string|null $baselinePath - Existing baseline to apply when not generating; null skips application.
     * @param bool $isBaselineExplicit - Whether the baseline path came from an explicit --baseline option.
     * @param string|null $generateBaselinePath - Output path for generation; null skips generation, which otherwise takes precedence.
     * @param string|null $migrateBaselinePath - 0.5 input used during generation; null generates solely from current findings.
     * @param bool $shouldForceBaselineOverwrite - Allow overwriting an older schema at the default filename; false keeps the overwrite guard.
     */
    public function __construct(
        public ?string $baselinePath,
        public bool $isBaselineExplicit,
        public ?string $generateBaselinePath,
        public ?string $migrateBaselinePath = null,
        public bool $shouldForceBaselineOverwrite = false,
    ) {
    }
}
