<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * Carries command-line and configuration options for mutation analysis.
 */
final readonly class MutationAnalysisOptions
{
    /**
     * Capture mutation-analysis inputs requested for an analyse invocation.
     *
     * @param string|null $infectionReportPath - Path to an existing Infection report.
     * @param bool        $shouldRunInfection - Whether gruff should invoke Infection directly.
     * @param string      $infectionBin - Infection executable path or command name.
     * @param string|null $infectionConfigPath - Infection config path, when supplied.
     * @param string|null $infectionTestFrameworkOptions - Extra test-framework options passed to Infection.
     * @param string|null $mutationBaselinePath - Mutation baseline path, when supplied.
     * @param int|null    $mutationBudget - Allowed mutation score budget, when configured.
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
