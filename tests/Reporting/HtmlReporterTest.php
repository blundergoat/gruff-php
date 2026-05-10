<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Reporting\HtmlReporter;
use GruffPhp\Scoring\ScoreCalculator;
use PHPUnit\Framework\TestCase;

final class HtmlReporterTest extends TestCase
{
    public function testHtmlReporterEscapesRunDataAndRendersReportSections(): void
    {
        $findings = [
            new Finding(
                ruleId: 'docs.missing-public-phpdoc',
                message: '<script>alert("x")</script>',
                filePath: 'src/<bad>.php',
                line: 4,
                severity: Severity::Warning,
                pillar: Pillar::Documentation,
                tier: RuleTier::V01,
                confidence: Confidence::High,
            ),
        ];
        $score = (new ScoreCalculator())->calculate($findings, null, DiffResult::inactive());
        $report = new AnalysisReport(
            toolVersion: '0.1.0-test',
            requestedPaths: ['src/<bad>.php'],
            format: 'html',
            failOn: 'none',
            filesDiscovered: 1,
            filesParsed: 1,
            ignoredPaths: [],
            missingPaths: [],
            diagnostics: [],
            findings: $findings,
            exitCode: 0,
            score: $score,
            diff: DiffResult::inactive(),
        );

        $html = (new HtmlReporter())->render($report);

        self::assertStringContainsString('<section class="verdict">', $html);
        self::assertStringContainsString('pillar grades', $html);
        self::assertStringContainsString('top offenders', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        self::assertStringContainsString('src/&lt;bad&gt;.php', $html);
        self::assertStringNotContainsString('<script>alert("x")</script>', $html);
        self::assertStringNotContainsString('fonts.googleapis.com', $html);
        self::assertStringNotContainsString('mutation', $html);
    }

    public function testHtmlReporterOmitsMutationVisualization(): void
    {
        $score = (new ScoreCalculator())->calculate([], null, DiffResult::inactive());
        $report = new AnalysisReport(
            toolVersion: '0.1.0-test',
            requestedPaths: ['src'],
            format: 'html',
            failOn: 'none',
            filesDiscovered: 0,
            filesParsed: 0,
            ignoredPaths: [],
            missingPaths: [],
            diagnostics: [],
            findings: [],
            exitCode: 0,
            score: $score,
            diff: DiffResult::inactive(),
        );

        $html = (new HtmlReporter())->render($report);

        self::assertStringContainsString('cyclomatic complexity · flagged methods', $html);
        self::assertStringNotContainsString('mutation', $html);
        self::assertStringNotContainsString('MSI', $html);
    }
}
