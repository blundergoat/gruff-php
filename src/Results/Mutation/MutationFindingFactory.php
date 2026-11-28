<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;

/**
 * Creates survivor, budget-breach, and MSI-regression findings from mutation analysis.
 * Survivor findings carry the mutant's file, optional line, status, and remediation.
 *
 * Budget and regression findings use a project-level location.
 */
final readonly class MutationFindingFactory
{
    /**
     * Builds one finding per escaped or timed-out mutant, plus applicable budget and MSI findings.
     *
     * @param MutationAnalysisResult $result - Mutation result supplying survivors, budget status, and the rounded MSI delta.
     *
     * @return list<Finding> - Survivor findings followed by any budget and negative-delta findings; empty when none apply.
     */
    public function findingsFor(MutationAnalysisResult $result): array
    {
        $findings = [];


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



        return $findings;
    }

    /**
     * Words the survived-mutant message to match how it survived, so a timeout doesn't read as a clean
     * escape the tests simply missed.
     *
     * @param InfectionMutant $infectionMutant - Survivor whose normalised status selects timeout or escape wording.
     *
     * @return string - Finding message naming the mutator; phrased to mark a timeout as "ran out of time" rather than a clean test pass.
     */
    private function survivedMessage(InfectionMutant $infectionMutant): string
    {
        // A timeout is not a clean escape, so give it its own wording.
        if ($infectionMutant->status === 'timed out') {


            return sprintf(
                'Mutation timed out via %s; Infection exceeded the timeout before a clear test failure.',
                $infectionMutant->mutator,
            );
        }


        return sprintf(
            'Mutation escaped via %s; tests did not fail against this mutant.',
            $infectionMutant->mutator,
        );
    }

    /**
     * Selects performance guidance for timeouts and test-strengthening guidance for escapes.
     *
     * @param InfectionMutant $infectionMutant - Survivor whose normalised status selects the recommended investigation.
     *
     * @return string - Remediation guidance; a timeout steers the reader to performance first, an escape to test strength.
     */
    private function survivedRemediation(InfectionMutant $infectionMutant): string
    {
        // A timeout gives no clear test-failure verdict, so investigate execution time before test strength.
        if ($infectionMutant->status === 'timed out') {

            return 'Investigate slow or non-terminating behavior first, then add or strengthen unit tests if the mutant should be killed; gruff-php consumes Infection output and does not generate mutants.';
        }


        return 'Add or strengthen unit tests that fail when this mutant changes behavior; gruff-php consumes Infection output and does not generate mutants.';
    }
}
