<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

/**
 * Carries aggregate mutation metrics and mutant details from Infection.
 */
final readonly class InfectionReport
{
    /**
     * @param string                   $reportPath Display path for the Infection report.
     * @param array<string, int|float> $stats      Numeric report stats keyed by Infection field.
     * @param list<InfectionMutant>    $mutants    Mutant rows parsed from the report.
     */
    public function __construct(
        public string $reportPath,
        public array $stats,
        public array $mutants,
    ) {
    }

    /**
     * Return the total mutant count from stats or parsed mutant rows.
     *
     * @return int Total mutant count.
     */
    public function totalMutants(): int
    {
        return (int) ($this->stats['totalMutantsCount'] ?? count($this->mutants));
    }

    /**
     * Return the mutation score indicator from the report stats.
     *
     * @return float MSI percentage.
     */
    public function msi(): float
    {
        return (float) ($this->stats['msi'] ?? 0.0);
    }

    /**
     * Return the covered-code mutation score indicator from the report stats.
     *
     * @return float Covered-code MSI percentage.
     */
    public function coveredMsi(): float
    {
        return (float) ($this->stats['coveredCodeMsi'] ?? 0.0);
    }

    /**
     * Return the mutation code coverage rate from the report stats.
     *
     * @return float Mutation code coverage percentage.
     */
    public function coverageRate(): float
    {
        return (float) ($this->stats['mutationCodeCoverage'] ?? 0.0);
    }

    /**
     * Return mutants that Infection reported as survived.
     *
     * @return list<InfectionMutant>
     */
    public function survivedMutants(): array
    {
        return array_values(array_filter(
            $this->mutants,
            static fn (InfectionMutant $infectionMutant): bool => in_array($infectionMutant->status, ['escaped', 'timed out'], true),
        ));
    }

    /**
     * Count mutants by normalized Infection status for human report context.
     *
     * @return array<string, int> Status counts keyed by normalized status label.
     */
    public function statusCounts(): array
    {
        $counts = [];

        foreach ($this->mutants as $mutant) {
            $counts[$mutant->status] ??= 0;
            $counts[$mutant->status]++;
        }

        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * Return per-file mutant counts from the parsed report.
     *
     * @return list<MutationFileSummary>
     */
    public function fileSummaries(): array
    {
        /** @var array<string, array{total: int, killed: int, survived: int, notCovered: int}> $byFile Accumulator shape is built incrementally per mutant file path. */
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
            $tested         = $counts['killed'] + $counts['survived'];
            $covered        = $tested;
            $msiDenominator = $tested + $counts['notCovered'];
            $msi            = $msiDenominator === 0 ? 0.0 : round(($counts['killed'] / $msiDenominator) * 100, 2);
            $coveredMsi     = $covered === 0 ? 0.0 : round(($counts['killed'] / $covered) * 100, 2);

            $summaries[] = new MutationFileSummary(
                filePath:          $file,
                totalMutants:      $counts['total'],
                killedMutants:     $counts['killed'],
                survivedMutants:   $counts['survived'],
                notCoveredMutants: $counts['notCovered'],
                msi:               $msi,
                coveredMsi:        $coveredMsi,
            );
        }

        return $summaries;
    }
}
