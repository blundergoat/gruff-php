<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * Carries aggregate mutation metrics and mutant details from Infection.
 */
final readonly class InfectionReport
{
    /**
      * User flow: Folds mutation results into the quality feedback users see.
      *
     * @param string                   $reportPath - Display path for the Infection report.
     * @param array<string, int|float> $stats - Numeric report stats keyed by Infection field.
     * @param list<InfectionMutant>    $mutants - Mutant rows parsed from the report.
     */
    public function __construct(
        public string $reportPath,
        public array  $stats,
        public array  $mutants,
    ) {
    }

    /**
     * Return the total mutant count from stats or parsed mutant rows.
     *
      * User flow: Folds mutation results into the quality feedback users see.
      *
     * @return int - count from Infection's aggregate stat, or the parsed row count when that stat is absent
     */
    public function totalMutants(): int
    {
        // User view: missing data becomes a safe mutation feedback default.
        return (int)($this->stats['totalMutantsCount'] ?? count($this->mutants));
    }

    /**
     * Return the mutation score indicator from the report stats.
     *
      * User flow: Folds mutation results into the quality feedback users see.
      *
     * @return float - mutation score indicator as a 0-100 percentage; 0.0 when the report omits the stat
     */
    public function msi(): float
    {
        // User view: missing data becomes a safe mutation feedback default.
        return (float)($this->stats['msi'] ?? 0.0);
    }

    /**
     * Return the covered-code mutation score indicator from the report stats.
     *
      * User flow: Folds mutation results into the quality feedback users see.
      *
     * @return float - covered-code MSI as a 0-100 percentage (killed over tested only); 0.0 on a partial report
     */
    public function coveredMsi(): float
    {
        // User view: missing data becomes a safe mutation feedback default.
        return (float)($this->stats['coveredCodeMsi'] ?? 0.0);
    }

    /**
     * Return the mutation code coverage rate from the report stats.
     *
      * User flow: Folds mutation results into the quality feedback users see.
      *
     * @return float - share of mutated code reached by tests as a 0-100 percentage; 0.0 when unreported
     */
    public function coverageRate(): float
    {
        // User view: missing data becomes a safe mutation feedback default.
        return (float)($this->stats['mutationCodeCoverage'] ?? 0.0);
    }

    /**
     * Return mutants that Infection reported as survived.
     *
      * User flow: Folds mutation results into the quality feedback users see.
      *
     * @return list<InfectionMutant> - re-indexed survivors (escaped or timed out); empty when none survived
     */
    public function survivedMutants(): array
    {
        return array_values(array_filter(
                                $this->mutants,
                                static fn(InfectionMutant $infectionMutant): bool => in_array($infectionMutant->status, ['escaped', 'timed out'], true),
                            ));
    }

    /**
     * Count mutants by normalized Infection status for human report context.
     *
      * User flow: Folds mutation results into the quality feedback users see.
      *
     * @return array<string, int> - mutant tallies keyed by status label, sorted by label for stable report ordering
     */
    public function statusCounts(): array
    {
        $counts = [];

        // User view: add each item that can appear in mutation feedback.
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
      * User flow: Folds mutation results into the quality feedback users see.
      *
     * @return list<MutationFileSummary> - one summary per source file, ordered by path; empty when no mutants exist
     */
    public function fileSummaries(): array
    {
        /** @var array<string, array{total: int, killed: int, survived: int, notCovered: int}> $byFile Accumulator shape is built incrementally per mutant file path. */
        $byFile = [];

        // User view: add each item that can appear in mutation feedback.
        foreach ($this->mutants as $mutant) {
            $byFile[$mutant->filePath] ??= ['total' => 0, 'killed' => 0, 'survived' => 0, 'notCovered' => 0];
            $byFile[$mutant->filePath]['total']++;

            // User view: choose the mutation feedback branch for this case.
            if (in_array($mutant->status, ['killed', 'killed by SA'], true)) {
                $byFile[$mutant->filePath]['killed']++;
            }

            // User view: choose the mutation feedback branch for this case.
            if (in_array($mutant->status, ['escaped', 'timed out'], true)) {
                $byFile[$mutant->filePath]['survived']++;
            }

            // User view: choose the mutation feedback branch for this case.
            if ($mutant->status === 'not covered') {
                $byFile[$mutant->filePath]['notCovered']++;
            }
        }

        ksort($byFile, SORT_STRING);
        $summaries = [];

        // User view: add each item that can appear in mutation feedback.
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
