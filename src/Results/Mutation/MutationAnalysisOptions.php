<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * Carries mutation-analysis settings from the command layer to the mutation builder.
 * The report path identifies output to parse whether Infection runs now or the report already exists.
 *
 * A null report path disables analysis and causes dependent flags to be diagnosed.
 */
final readonly class MutationAnalysisOptions
{
    /**
     * Stores mutation report, runner, baseline, and budget choices for one analysis.
     *
     * @param string|null $infectionReportPath - Report to read after an optional run; null skips analysis and diagnoses dependent flags.
     * @param bool $shouldRunInfection - Whether to launch Infection before reading the requested report.
     * @param string $infectionBin - Executable path or command name used when launching Infection.
     * @param string|null $infectionConfigPath - Explicit config; null uses project infection.json5 when present, otherwise Infection's default.
     * @param string|null $infectionTestFrameworkOptions - Options forwarded to Infection's test framework; null or blank forwards none.
     * @param string|null $mutationBaselinePath - Comparison report; null leaves score movement unavailable.
     * @param int|null $mutationBudget - Maximum tolerated survivors; null disables the survivor-budget check.
     */
    public function __construct(
        public ?string $infectionReportPath,
        public bool $shouldRunInfection,
        public string $infectionBin,
        public ?string $infectionConfigPath,
        public ?string $infectionTestFrameworkOptions,
        public ?string $mutationBaselinePath,
        public ?int $mutationBudget,
    ) {
    }
}
