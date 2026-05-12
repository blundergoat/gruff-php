<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

/**
 * Carries command-line and configuration options for mutation analysis.
 */
final readonly class MutationAnalysisOptions
{
    /**
     * Capture mutation-analysis inputs requested for an analyse invocation.
     *
     * @param string|null $infectionReportPath Path to an existing Infection report.
     * @param bool $infectionRun Whether gruff should invoke Infection directly.
     * @param string $infectionBin Infection executable path or command name.
     * @param string|null $infectionConfigPath Infection config path, when supplied.
     * @param string|null $infectionTestFrameworkOptions Extra test-framework options passed to Infection.
     * @param string|null $mutationBaselinePath Mutation baseline path, when supplied.
     * @param int|null $mutationBudget Allowed mutation score budget, when configured.
     */
    public function __construct(
        public ?string $infectionReportPath,
        public bool $infectionRun,
        public string $infectionBin,
        public ?string $infectionConfigPath,
        public ?string $infectionTestFrameworkOptions,
        public ?string $mutationBaselinePath,
        public ?int $mutationBudget,
    ) {
    }
}
