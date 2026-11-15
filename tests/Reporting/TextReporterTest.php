<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Output\Reporter\TextReporter;
use PHPUnit\Framework\TestCase;

/**
 * Covers the text reporter's output-volume hint and handling of remediation metadata.
 * Builds synthetic advisory findings for a single discovered and parsed file.
 * Checks that Apply metadata leaves the rendered text unchanged.
 */
final class TextReporterTest extends TestCase
{
    /**
     * Verify the hint is suppressed when finding count is below the threshold.
     *
     * @return void - Asserts that both footer hint fragments are absent for five findings.
     */
    public function testFooterHintIsSuppressedBelowThreshold(): void
    {
        $report = $this->reportWithFindings(5);

        $output = (new TextReporter())->render($report);

        self::assertStringNotContainsString('Hint:', $output);
        self::assertStringNotContainsString('summary <paths>', $output);
    }

    /**
     * Verify the hint surfaces for a report containing 60 findings.
     *
     * @return void - Asserts both threshold hint fragments appear for 60 findings.
     */
    public function testFooterHintAppearsAtThreshold(): void
    {
        $report = $this->reportWithFindings(60);

        $output = (new TextReporter())->render($report);

        self::assertStringContainsString('Hint: 60 findings is a lot to read flat.', $output);
        self::assertStringContainsString('php bin/gruff-php summary <paths>', $output);
    }

    /**
     * Verify action metadata does not alter the family-frozen text finding block.
     *
     * @return void - Asserts equal renderings and the absence of remediationAction and APPLY text.
     */
    public function testRemediationActionMetadataDoesNotChangeTextOutput(): void
    {
        $reporter   = new TextReporter();
        $plain      = $reporter->render($this->reportWithFindings(1));
        $classified = $reporter->render($this->reportWithFindings(
            1,
            RemediationAction::Apply->metadata(),
        ));

        self::assertSame($plain, $classified);
        self::assertStringNotContainsString('remediationAction', $classified);
        self::assertStringNotContainsString('APPLY', $classified);
    }

    /**
     * Build an advisory-only report for one discovered and parsed file.
     *
     * @param int                  $findingCount - Requested finding count; zero or negative produces no findings.
     * @param array<string, bool|float|int|string|null|array<array-key, bool|float|int|string|null>> $metadata - Shared fields; empty adds none.
     *
     * @return AnalysisReport - Report for one file with max(0, $findingCount) advisory findings and exit code 0.
     */
    private function reportWithFindings(int $findingCount, array $metadata = []): AnalysisReport
    {
        $findings = [];
        for ($index = 0; $index < $findingCount; $index++) {
            $findings[] = new Finding(
                ruleId:     'docs.missing-phpdoc',
                message:    'Example finding.',
                filePath:   'src/Example.php',
                line:       $index + 1,
                severity:   Severity::Advisory,
                pillar:     Pillar::Documentation,
                tier:       RuleTier::V01,
                confidence: Confidence::High,
                metadata:   $metadata,
            );
        }

        return new AnalysisReport(
            toolVersion:     '0.2.0',
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
}
