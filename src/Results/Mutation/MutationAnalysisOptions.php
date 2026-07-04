<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * The mutation-analysis knobs for one `analyse` run, gathered into a single readonly value.
 *
 * A user turns mutation testing on and shapes it through flags and config - pointing at an existing
 * Infection report, asking gruff to run Infection itself, or naming the binary, config, baseline,
 * budget, and test-framework options. This object carries those choices from the command layer into
 * the mutation builder, so the run knows whether to read a report, shell out to Infection, or leave
 * mutation feedback out of the results entirely.
 */
final readonly class MutationAnalysisOptions
{
    /**
     * Captures every mutation input the user requested for this run, so the builder can act on their
     * choices without re-reading command-line flags.
     *
     * @param string|null $infectionReportPath - Existing Infection report to read; null when the user pointed at none, so gruff either runs Infection itself or skips mutation analysis.
     * @param bool        $shouldRunInfection - True when the user asked gruff to invoke Infection directly rather than read a report they already generated.
     * @param string      $infectionBin - Infection executable path or command name to launch when running it directly.
     * @param string|null $infectionConfigPath - Infection config file to use; null leaves Infection to find its own default.
     * @param string|null $infectionTestFrameworkOptions - Extra options forwarded to the test framework Infection drives; null when the user added none.
     * @param string|null $mutationBaselinePath - Earlier report to compare against; null when the user set no baseline, so no score movement is shown.
     * @param int|null    $mutationBudget - Most surviving mutants the run tolerates before it fails; null when the user set no budget, so survivors only report.
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
