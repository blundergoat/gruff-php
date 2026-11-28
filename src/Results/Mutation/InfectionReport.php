<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * Holds one Infection report's aggregate metrics and individual mutant records.
 * InfectionReportParser constructs this value from JSON for mutation analysis.
 *
 * Query methods also support directly constructed data, with fallbacks for missing metrics.
 */
final readonly class InfectionReport
{
    /**
     * Stores the report's display path, numeric statistics, and mutant records.
     *
     * @param string $reportPath - Display path identifying the report in mutation output.
     * @param array<string, int|float> $stats - Numeric metrics; missing total uses the row count, while missing score metrics use zero.
     * @param list<InfectionMutant> $mutants - Records used for status and per-file summaries; empty produces empty views.
     */
    public function __construct(
        public string $reportPath,
        public array  $stats,
        public array  $mutants,
    ) {
    }

    /**
     * The "N total" figure in the report's `Mutants:` line - how many code mutations Infection tried.
     *
     * @return int - Infection's own total; when a partial report drops that stat, the count of parsed mutant rows instead.
     */
    public function totalMutants(): int
    {

        return (int)($this->stats['totalMutantsCount'] ?? count($this->mutants));
    }

    /**
     * The headline mutation score the user reads first, and the number the pillar grade is built from.
     *
     * @return float - Mutation score indicator as a 0-100 percentage; 0.0 when the report omits the stat, which grades as a total miss.
     */
    public function msi(): float
    {

        return (float)($this->stats['msi'] ?? 0.0);
    }

    /**
     * Supplies Infection's reported mutation score for covered code.
     *
     * @return float - Reported covered-code MSI percentage, or 0.0 when the statistic is absent.
     */
    public function coveredMsi(): float
    {

        return (float)($this->stats['coveredCodeMsi'] ?? 0.0);
    }

    /**
     * The "Mutation coverage" percentage in the report - how much of the mutated code the tests reach at all.
     *
     * @return float - Share of mutated code exercised by tests as a 0-100 percentage; 0.0 when the report never recorded it.
     */
    public function coverageRate(): float
    {

        return (float)($this->stats['mutationCodeCoverage'] ?? 0.0);
    }

    /**
     * Selects escaped and timed-out mutants for survivor findings.
     *
     * @return list<InfectionMutant> - Re-indexed escaped or timed-out records; empty when neither status occurs.
     */
    public function survivedMutants(): array
    {

        return array_values(array_filter(
                                $this->mutants,
                                static fn(InfectionMutant $infectionMutant): bool => in_array($infectionMutant->status, ['escaped', 'timed out'], true),
                            ));
    }

    /**
     * Counts mutants by their stored Infection status.
     *
     * @return array<string, int> - Counts sorted by status label; empty when no mutants are stored.
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
     * The per-file rows in the report's Files: block - one MSI breakdown for each source file that had mutants.
     *
     * @return list<MutationFileSummary> - One summary per source file, ordered by path; empty when the run produced no mutants at all.
     */
    public function fileSummaries(): array
    {
        /** @var array<string, array{total: int, killed: int, survived: int, notCovered: int}> $byFile - Counts grouped by source path for MSI. */
        $byFile = [];


        foreach ($this->mutants as $mutant) {

            $byFile[$mutant->filePath] ??= ['total' => 0, 'killed' => 0, 'survived' => 0, 'notCovered' => 0];
            $byFile[$mutant->filePath]['total']++;

            // Count static-analysis kills with test kills in each file's MSI.
            if (in_array($mutant->status, ['killed', 'killed by SA'], true)) {
                $byFile[$mutant->filePath]['killed']++;
            }


            if (in_array($mutant->status, ['escaped', 'timed out'], true)) {
                $byFile[$mutant->filePath]['survived']++;
            }

            // A not-covered mutant sits on code no test even runs, so it drags MSI down without counting as tested.
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
