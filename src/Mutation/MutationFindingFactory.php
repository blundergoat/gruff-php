<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;

final readonly class MutationFindingFactory
{
    /**
     * @return list<Finding>
     */
    public function findingsFor(MutationAnalysisResult $result): array
    {
        $findings = [];

        foreach ($result->report->survivedMutants() as $mutant) {
            $findings[] = new Finding(
                ruleId: 'mutation.survived-mutant',
                message: sprintf('Mutation survived via %s (%s).', $mutant->mutator, $mutant->status),
                filePath: $mutant->filePath,
                line: $mutant->line,
                severity: Severity::Warning,
                pillar: Pillar::Mutation,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                symbol: $mutant->mutator,
                remediation: 'Add or strengthen unit tests that fail when this mutant changes behavior; gruff-php consumes Infection output and does not generate mutants.',
                metadata: [
                    'status' => $mutant->status,
                    'mutator' => $mutant->mutator,
                    'msi' => $result->report->msi(),
                    'coveredMsi' => $result->report->coveredMsi(),
                    'mutationCodeCoverage' => $result->report->coverageRate(),
                    'diff' => $mutant->diff,
                    'processOutput' => $mutant->processOutput,
                ],
            );
        }

        if ($result->budgetExceeded()) {
            $findings[] = new Finding(
                ruleId: 'mutation.budget-exceeded',
                message: sprintf(
                    'Mutation budget exceeded: %d survived mutants found, limit is %d.',
                    $result->survivedCount(),
                    $result->mutationBudget,
                ),
                filePath: '.',
                line: null,
                severity: Severity::Warning,
                pillar: Pillar::Mutation,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                remediation: 'Reduce escaped/timed-out mutants or raise the explicit mutation budget for this run.',
                metadata: [
                    'limit' => $result->mutationBudget,
                    'survivedMutants' => $result->survivedCount(),
                ],
            );
        }

        $delta = $result->msiDelta();
        if ($delta !== null && $delta < 0) {
            $findings[] = new Finding(
                ruleId: 'mutation.msi-regression',
                message: sprintf('Mutation score regressed by %.2f percentage points versus baseline.', abs($delta)),
                filePath: '.',
                line: null,
                severity: Severity::Warning,
                pillar: Pillar::Mutation,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                remediation: 'Inspect survived mutants introduced since the baseline and either improve unit tests or accept the lower MSI deliberately.',
                metadata: [
                    'currentMsi' => $result->report->msi(),
                    'baselineMsi' => $result->baselineReport?->msi(),
                    'delta' => $delta,
                ],
            );
        }

        return $findings;
    }
}
