<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * The baseline decisions for one `gruff-php analyse` run, frozen while the command line is parsed.
 *
 * The command layer fills this in from `--baseline`, `--generate-baseline`, `--migrate-baseline`, and `--no-baseline`, then hands it to
 * `BaselineApplication`, which decides whether the run hides reviewed findings, records the current findings, or shows everything.
 */
final readonly class BaselineApplicationOptions
{
    /**
     * Freezes the baseline choices for one analyse run.
     *
     * @param string|null $baselinePath - Baseline whose reviewed findings this run hides; null when none applies, so every finding is shown.
     * @param bool        $isBaselineExplicit - True when the user typed `--baseline`; false when the path is the discovered project default.
     * @param string|null $generateBaselinePath - Path to write a fresh baseline to; null on a normal run. Any path here wins over applying one.
     * @param string|null $migrateBaselinePath - 0.5 baseline whose reviewed findings are carried into $generateBaselinePath; null when generating from the scan alone.
     * @param bool        $shouldForceBaselineOverwrite - True when the user passed --force and means to overwrite a 0.5
     *                                                    baseline at the default path; false keeps their retreat copy.
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
