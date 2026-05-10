<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

final readonly class MutationAnalysisOptions
{
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
