<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * The parsed, in-memory form of one Infection run - the headline MSI figures plus every
 * individual mutant row the tool reported.
 *
 * `InfectionReportParser` builds this once from Infection's JSON (the user gets here by running
 * `gruff-php analyse --infection-report=infection.log.json`); the rest of gruff then reads it -
 * the terminal and Markdown reporters print its `Mutation` section, the score calculator turns
 * its MSI into a pillar grade, and the finding factory turns its survivors into the findings
 * that tell a user which mutations slipped past their tests.
 */
final readonly class InfectionReport
{
    /**
     * Holds one Infection run's parsed output; always built by `InfectionReportParser`, never by hand.
     *
     * @param string                   $reportPath - Display path of the report, echoed in the `Mutation` section's `Source:` line.
     * @param array<string, int|float> $stats - Aggregate figures keyed by Infection's JSON field names; a partial or empty map means the query methods below fall back to zero.
     * @param list<InfectionMutant>    $mutants - Individual mutant rows; empty means Infection produced no mutants, so every per-file and status view comes out blank.
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
        // Trust Infection's headline count; only fall back to counting rows if that stat didn't survive parsing.
        return (int)($this->stats['totalMutantsCount'] ?? count($this->mutants));
    }

    /**
     * The headline mutation score the user reads first, and the number the pillar grade is built from.
     *
     * @return float - Mutation score indicator as a 0-100 percentage; 0.0 when the report omits the stat, which grades as a total miss.
     */
    public function msi(): float
    {
        // A missing `msi` reads as 0.0, so an incomplete report grades as "nothing killed" rather than crashing.
        return (float)($this->stats['msi'] ?? 0.0);
    }

    /**
     * The companion score to MSI: how well tests kill mutations in code they actually run. It ignores uncovered code, so it always reads at or above MSI.
     *
     * @return float - Covered-code MSI as a 0-100 percentage (killed over tested only); 0.0 on a partial report missing the stat.
     */
    public function coveredMsi(): float
    {
        // Absent covered-MSI collapses to 0.0, keeping the "of the code tests do reach" figure safe on a partial run.
        return (float)($this->stats['coveredCodeMsi'] ?? 0.0);
    }

    /**
     * The "Mutation coverage" percentage in the report - how much of the mutated code the tests reach at all.
     *
     * @return float - Share of mutated code exercised by tests as a 0-100 percentage; 0.0 when the report never recorded it.
     */
    public function coverageRate(): float
    {
        // No coverage stat means 0.0 here, telling the user none of the mutated code was reached rather than hiding a gap.
        return (float)($this->stats['mutationCodeCoverage'] ?? 0.0);
    }

    /**
     * The mutants that beat the suite - this exact list is what becomes `mutation.survived-mutant` findings.
     *
     * @return list<InfectionMutant> - Re-indexed survivors, meaning escaped or timed-out mutants; empty when none survived - every mutant was killed or not covered, or there were none at all.
     */
    public function survivedMutants(): array
    {
        // "Survived" means the mutation lived through the tests: it escaped outright, or ran long enough to time out.
        return array_values(array_filter(
                                $this->mutants,
                                static fn(InfectionMutant $infectionMutant): bool => in_array($infectionMutant->status, ['escaped', 'timed out'], true),
                            ));
    }

    /**
     * The counts behind the report's `Statuses:` line - how many mutants landed in each Infection outcome.
     *
     * @return array<string, int> - Tally keyed by status label, sorted by label so the rendered breakdown stays stable run to run; empty when there are no mutants.
     */
    public function statusCounts(): array
    {
        $counts = [];

        // Walk every mutant and bump its status bucket so the report can show the full outcome breakdown.
        foreach ($this->mutants as $mutant) {
            // First time a status shows up this run, seed its bucket at zero before counting into it.
            $counts[$mutant->status] ??= 0;
            $counts[$mutant->status]++;
        }

        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * The per-file rows in the report's `Files:` block - one MSI breakdown for each source file that had mutants.
     *
     * @return list<MutationFileSummary> - One summary per source file, ordered by path; empty when the run produced no mutants at all.
     */
    public function fileSummaries(): array
    {
        /** @var array<string, array{total: int, killed: int, survived: int, notCovered: int}> $byFile Accumulator shape is built incrementally per mutant file path. */
        $byFile = [];

        // Fold every mutant into a per-file tally so each source file can get its own row later.
        foreach ($this->mutants as $mutant) {
            // First mutant seen for a file opens its counter row before anything is added to it.
            $byFile[$mutant->filePath] ??= ['total' => 0, 'killed' => 0, 'survived' => 0, 'notCovered' => 0];
            $byFile[$mutant->filePath]['total']++;

            // A killed mutant is the good case - the change was caught, whether by the tests or (for `killed by SA`) by static analysis before the tests even ran.
            if (in_array($mutant->status, ['killed', 'killed by SA'], true)) {
                $byFile[$mutant->filePath]['killed']++;
            }

            // An escaped or timed-out mutant is a survivor: it counts against the file as a gap the tests missed.
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

        // Turn each file's raw tallies into the two MSI percentages the report actually displays.
        foreach ($byFile as $file => $counts) {
            $tested         = $counts['killed'] + $counts['survived'];
            $covered        = $tested;
            $msiDenominator = $tested + $counts['notCovered'];
            // With nothing tested or not-covered to divide by, show 0% for this file rather than dividing by zero.
            $msi            = $msiDenominator === 0 ? 0.0 : round(($counts['killed'] / $msiDenominator) * 100, 2);
            // Covered MSI ignores not-covered mutants; when the file has none tested at all, it too reads 0% rather than erroring.
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
