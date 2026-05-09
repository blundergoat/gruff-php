<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

final readonly class InfectionReport
{
    /**
     * @param array<string, int|float> $stats
     * @param list<InfectionMutant> $mutants
     */
    public function __construct(
        public string $reportPath,
        public array $stats,
        public array $mutants,
    ) {
    }

    public function totalMutants(): int
    {
        return (int) ($this->stats['totalMutantsCount'] ?? count($this->mutants));
    }

    public function msi(): float
    {
        return (float) ($this->stats['msi'] ?? 0.0);
    }

    public function coveredMsi(): float
    {
        return (float) ($this->stats['coveredCodeMsi'] ?? 0.0);
    }

    public function coverageRate(): float
    {
        return (float) ($this->stats['mutationCodeCoverage'] ?? 0.0);
    }

    /**
     * @return list<InfectionMutant>
     */
    public function survivedMutants(): array
    {
        return array_values(array_filter(
            $this->mutants,
            static fn (InfectionMutant $mutant): bool => in_array($mutant->status, ['escaped', 'timed out'], true),
        ));
    }

    /**
     * @return list<MutationFileSummary>
     */
    public function fileSummaries(): array
    {
        /** @var array<string, array{total: int, killed: int, survived: int, notCovered: int}> $byFile */
        $byFile = [];

        foreach ($this->mutants as $mutant) {
            $byFile[$mutant->filePath] ??= ['total' => 0, 'killed' => 0, 'survived' => 0, 'notCovered' => 0];
            $byFile[$mutant->filePath]['total']++;

            if (in_array($mutant->status, ['killed', 'killed by SA'], true)) {
                $byFile[$mutant->filePath]['killed']++;
            }

            if (in_array($mutant->status, ['escaped', 'timed out'], true)) {
                $byFile[$mutant->filePath]['survived']++;
            }

            if ($mutant->status === 'not covered') {
                $byFile[$mutant->filePath]['notCovered']++;
            }
        }

        ksort($byFile, SORT_STRING);
        $summaries = [];

        foreach ($byFile as $file => $counts) {
            $tested = $counts['killed'] + $counts['survived'];
            $covered = $tested;
            $msiDenominator = $tested + $counts['notCovered'];
            $msi = $msiDenominator === 0 ? 0.0 : round(($counts['killed'] / $msiDenominator) * 100, 2);
            $coveredMsi = $covered === 0 ? 0.0 : round(($counts['killed'] / $covered) * 100, 2);

            $summaries[] = new MutationFileSummary(
                filePath: $file,
                totalMutants: $counts['total'],
                killedMutants: $counts['killed'],
                survivedMutants: $counts['survived'],
                notCoveredMutants: $counts['notCovered'],
                msi: $msi,
                coveredMsi: $coveredMsi,
            );
        }

        return $summaries;
    }
}
