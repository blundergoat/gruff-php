<?php

declare(strict_types=1);

namespace GruffPhp\Scoring;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;

final readonly class CompositeFindingFactory
{
    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    public function build(array $findings): array
    {
        /** @var array<string, list<Finding>> $bySymbol */
        $bySymbol = [];

        foreach ($findings as $finding) {
            if ($finding->symbol === null || $finding->line === null) {
                continue;
            }

            $key = $finding->filePath . "\0" . $finding->symbol;
            $bySymbol[$key] ??= [];
            $bySymbol[$key][] = $finding;
        }

        $composites = [];

        foreach ($bySymbol as $group) {
            $complexityRules = array_values(array_filter(
                $group,
                static fn (Finding $finding): bool => in_array($finding->ruleId, [
                    'complexity.cognitive',
                    'complexity.cyclomatic',
                    'complexity.nesting-depth',
                    'complexity.npath',
                ], true),
            ));
            $sizeRules = array_values(array_filter(
                $group,
                static fn (Finding $finding): bool => in_array($finding->ruleId, [
                    'size.method-length',
                    'size.parameter-count',
                ], true),
            ));

            if ($complexityRules === [] || $sizeRules === []) {
                continue;
            }

            $first = $group[0];
            $lines = array_values(array_filter(
                array_map(static fn (Finding $finding): ?int => $finding->line, $group),
                static fn (?int $line): bool => $line !== null,
            ));
            $endLines = array_values(array_filter(
                array_map(static fn (Finding $finding): ?int => $finding->endLine, $group),
                static fn (?int $line): bool => $line !== null,
            ));
            if ($lines === []) {
                continue;
            }

            $componentRuleIds = array_values(array_unique(array_map(
                static fn (Finding $finding): string => $finding->ruleId,
                array_merge($complexityRules, $sizeRules),
            )));
            sort($componentRuleIds, SORT_STRING);

            $composites[] = new Finding(
                ruleId: 'design.god-method',
                message: sprintf('%s combines size and complexity findings; split it before adding more behavior.', $first->symbol),
                filePath: $first->filePath,
                line: min($lines),
                severity: Severity::Warning,
                pillar: Pillar::Design,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                endLine: $endLines === [] ? $first->endLine : max($endLines),
                symbol: $first->symbol,
                remediation: 'Extract branches or responsibilities until size and complexity findings no longer overlap on the same method.',
                secondaryPillars: [Pillar::Complexity, Pillar::Size],
                metadata: [
                    'componentRules' => $componentRuleIds,
                ],
            );
        }

        return $composites;
    }
}
