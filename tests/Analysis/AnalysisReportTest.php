<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Analysis;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Covers the AnalysisReport aggregation accessors used by triage views,
 * specifically the per-rule count buckets surfaced for M08's rule-grouped
 * triage workflow.
 */
final class AnalysisReportTest extends TestCase
{
    /**
     * Verify findingCountsByRule sums severities per rule and ranks rules by total.
     *
     * @return void
     */
    public function testFindingCountsByRuleAggregatesAndRanks(): void
    {
        $report = $this->reportFor([
            $this->finding('docs.missing-public-phpdoc', Severity::Advisory),
            $this->finding('docs.missing-public-phpdoc', Severity::Warning),
            $this->finding('docs.missing-public-phpdoc', Severity::Advisory),
            $this->finding('size.method-length', Severity::Error),
            $this->finding('size.method-length', Severity::Error),
            $this->finding('alpha.rule', Severity::Advisory),
        ]);

        self::assertSame(
            [
                ['ruleId' => 'docs.missing-public-phpdoc', 'total' => 3, 'advisory' => 2, 'warning' => 1, 'error' => 0],
                ['ruleId' => 'size.method-length', 'total' => 2, 'advisory' => 0, 'warning' => 0, 'error' => 2],
                ['ruleId' => 'alpha.rule', 'total' => 1, 'advisory' => 1, 'warning' => 0, 'error' => 0],
            ],
            $report->findingCountsByRule(),
        );
    }

    /**
     * Verify ties on total sort by rule id ascending for deterministic output.
     *
     * @return void
     */
    public function testFindingCountsByRuleBreaksTotalTiesByRuleId(): void
    {
        $report = $this->reportFor([
            $this->finding('zeta.rule', Severity::Advisory),
            $this->finding('alpha.rule', Severity::Advisory),
            $this->finding('beta.rule', Severity::Advisory),
        ]);

        $ruleIds = array_map(static fn (array $row): string => $row['ruleId'], $report->findingCountsByRule());
        self::assertSame(['alpha.rule', 'beta.rule', 'zeta.rule'], $ruleIds);
    }

    /**
     * Build an AnalysisReport from the supplied findings.
     *
     * @param list<Finding> $findings
     * @return AnalysisReport
     */
    private function reportFor(array $findings): AnalysisReport
    {
        return new AnalysisReport(
            toolVersion:     '0.1.4',
            requestedPaths:  ['src'],
            format:          'text',
            failOn:          'none',
            filesDiscovered: 1,
            filesParsed:     1,
            ignoredPaths:    [],
            missingPaths:    [],
            diagnostics:     [],
            findings:        $findings,
            exitCode:        0,
        );
    }

    /**
     * Build a finding fixture for aggregation tests.
     *
     * @return Finding
     */
    private function finding(string $ruleId, Severity $severity): Finding
    {
        return new Finding(
            ruleId:     $ruleId,
            message:    'Example finding.',
            filePath:   'src/Example.php',
            line:       1,
            severity:   $severity,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );
    }
}
