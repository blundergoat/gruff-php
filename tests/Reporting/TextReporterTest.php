<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Reporting\TextReporter;
use PHPUnit\Framework\TestCase;

/**
 * Covers the output-volume hint that points users at `summary` when an analyse run
 * crosses the high-watermark for flat-list browsability. See M08.
 */
final class TextReporterTest extends TestCase
{
    /**
     * Verify the hint is suppressed when finding count is below the threshold.
     *
     * @return void
     */
    public function testFooterHintIsSuppressedBelowThreshold(): void
    {
        $report = $this->reportWithFindings(5);

        $output = (new TextReporter())->render($report);

        self::assertStringNotContainsString('Hint:', $output);
        self::assertStringNotContainsString('summary <paths>', $output);
    }

    /**
     * Verify the hint surfaces when the finding count reaches the threshold.
     *
     * @return void
     */
    public function testFooterHintAppearsAtThreshold(): void
    {
        $report = $this->reportWithFindings(60);

        $output = (new TextReporter())->render($report);

        self::assertStringContainsString('Hint: 60 findings is a lot to read flat.', $output);
        self::assertStringContainsString('php bin/gruff-php summary <paths>', $output);
    }

    /**
     * Build a synthetic AnalysisReport with the requested number of findings.
     *
     * @param int $findingCount - Number of synthetic findings to embed.
     *
     * @return AnalysisReport - report carrying exactly $findingCount advisory findings over one discovered/parsed file, exit code 0
     */
    private function reportWithFindings(int $findingCount): AnalysisReport
    {
        $findings = [];
        for ($index = 0; $index < $findingCount; $index++) {
            $findings[] = new Finding(
                ruleId:     'docs.missing-public-phpdoc',
                message:    'Example finding.',
                filePath:   'src/Example.php',
                line:       $index + 1,
                severity:   Severity::Advisory,
                pillar:     Pillar::Documentation,
                tier:       RuleTier::V01,
                confidence: Confidence::High,
            );
        }

        // One file/parse pair keeps the fixture minimal; only the findings count drives the footer-hint assertion.
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
