<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;

/**
 * Turns a mutation-analysis result into the findings a user actually sees and acts on.
 *
 * Mutation numbers on their own don't tell a user what to do; this factory translates them into
 * concrete findings. It raises one `mutation.survived-mutant` finding per mutation the tests failed to
 * kill, a `mutation.budget-exceeded` finding when too many survived, and a `mutation.msi-regression`
 * finding when the score dropped versus the baseline - each with a message and remediation pointing at
 * the weak test, so mutation feedback lands in the same findings list as every other check.
 */
final readonly class MutationFindingFactory
{
    /**
     * Builds the findings for one mutation run: one per survived mutant, plus a budget-breach and an
     * MSI-regression finding when those gates trip - the whole of what mutation analysis tells the user.
     *
     * @param MutationAnalysisResult $result - Mutation analysis result to convert into findings.
     *
     * @return list<Finding> - Findings for any survived mutants, budget breach, and MSI regression this run produced; empty when all three gate signals are clear.
     */
    public function findingsFor(MutationAnalysisResult $result): array
    {
        $findings = [];

        // One finding per mutation that slipped past the tests, pinned to the file and line it lives on.
        foreach ($result->report->survivedMutants() as $infectionMutant) {
            $findings[] = new Finding(
                ruleId:      'mutation.survived-mutant',
                message:     $this->survivedMessage($infectionMutant),
                filePath:    $infectionMutant->filePath,
                line:        $infectionMutant->line,
                severity:    Severity::Warning,
                pillar:      Pillar::Mutation,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                symbol:      $infectionMutant->mutator,
                remediation: $this->survivedRemediation($infectionMutant),
                metadata:    [
                                 'status'               => $infectionMutant->status,
                                 'mutator'              => $infectionMutant->mutator,
                                 'msi'                  => $result->report->msi(),
                                 'coveredMsi'           => $result->report->coveredMsi(),
                                 'mutationCodeCoverage' => $result->report->coverageRate(),
                                 'diff'                 => $infectionMutant->diff,
                                 'processOutput'        => $infectionMutant->processOutput,
                             ],
            );
        }

        // Too many survivors for the budget the user set: one project-level finding that fails the run.
        if ($result->isBudgetExceeded()) {
            $findings[] = new Finding(
                ruleId:      'mutation.budget-exceeded',
                message:     sprintf(
                                 'Mutation budget exceeded: %d survived mutants found, limit is %d.',
                                 $result->survivedCount(),
                                 $result->mutationBudget,
                             ),
                filePath:    '.',
                line:        null,
                severity:    Severity::Warning,
                pillar:      Pillar::Mutation,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                remediation: 'Reduce escaped/timed-out mutants or raise the explicit mutation budget for this run.',
                metadata:    [
                                 'limit'           => $result->mutationBudget,
                                 'survivedMutants' => $result->survivedCount(),
                             ],
            );
        }

        $delta = $result->msiDelta();
        // The score fell versus the baseline (a negative delta), so flag the regression; a null delta means there was no baseline to compare against.
        if ($delta !== null && $delta < 0) {
            $findings[] = new Finding(
                ruleId:      'mutation.msi-regression',
                message:     sprintf('Mutation score regressed by %.2f percentage points versus baseline.', abs($delta)),
                filePath:    '.',
                line:        null,
                severity:    Severity::Warning,
                pillar:      Pillar::Mutation,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                remediation: 'Inspect survived mutants introduced since the baseline and either improve unit tests or accept the lower MSI deliberately.',
                metadata:    [
                                 'currentMsi'  => $result->report->msi(),
                                 'baselineMsi' => $result->baselineReport?->msi(),
                                 'delta'       => $delta,
                             ],
            );
        }

        // Survived mutants, budget breach, and MSI regression are the three independent
        // gate signals; hand back whichever subset this result produced.
        return $findings;
    }

    /**
     * Words the survived-mutant message to match how it survived, so a timeout doesn't read as a clean
     * escape the tests simply missed.
     *
     * @param InfectionMutant $infectionMutant - Survived mutant whose status selects the wording; status is the
     *                                          raw Infection label, so only 'timed out' diverges from the escaped case.
     *
     * @return string - Finding message naming the mutator; phrased to mark a timeout as "ran out of time" rather than a clean test pass.
     */
    private function survivedMessage(InfectionMutant $infectionMutant): string
    {
        // A timeout is not a clean escape, so give it its own wording.
        if ($infectionMutant->status === 'timed out') {
            // A timeout is not a clean escape: Infection ran out of time before any test verdict,
            // so the wording avoids implying the tests actually passed.
            return sprintf(
                'Mutation timed out via %s; Infection exceeded the timeout before a clear test failure.',
                $infectionMutant->mutator,
            );
        }

        // Default escaped case: the suite ran to completion and no test failed against the mutant.
        return sprintf(
            'Mutation escaped via %s; tests did not fail against this mutant.',
            $infectionMutant->mutator,
        );
    }

    /**
     * Picks remediation guidance that matches how the mutant survived - performance first for a
     * timeout, test strength for an escape - so the user's next step is the one that will help.
     *
     * @param InfectionMutant $infectionMutant - Survived mutant whose status selects the guidance; a 'timed out'
     *                                          status points the developer at performance before test strength.
     *
     * @return string - Remediation guidance; a timeout steers the reader to performance first, an escape to test strength.
     */
    private function survivedRemediation(InfectionMutant $infectionMutant): string
    {
        // A timeout usually means slow code rather than a coverage gap, so its guidance leads with speed.
        if ($infectionMutant->status === 'timed out') {
            // Timeouts are usually a speed problem, not a coverage gap, so steer the reader to that first.
            return 'Investigate slow or non-terminating behavior first, then add or strengthen unit tests if the mutant should be killed; gruff-php consumes Infection output and does not generate mutants.';
        }

        // Escaped mutant: the only fix is a test that fails on the mutated behavior.
        return 'Add or strengthen unit tests that fail when this mutant changes behavior; gruff-php consumes Infection output and does not generate mutants.';
    }
}
