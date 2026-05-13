<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
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
    /**
     * Verify HTML reporter escapes run data and renders report sections.
     *
     * @return void No return value.
     */
    public function testHtmlReporterEscapesRunDataAndRendersReportSections(): void
    {
        $findings = [
            new Finding(
                ruleId:     'docs.missing-public-phpdoc',
                message:    '<script>alert("x")</script>',
                filePath:   'src/<bad>.php',
                line:       4,
                severity:   Severity::Warning,
                pillar:     Pillar::Documentation,
                tier:       RuleTier::V01,
                confidence: Confidence::High,
            ),
        ];
        $score  = (new ScoreCalculator())->calculate($findings, null, DiffResult::inactive());
        $report = new AnalysisReport(
            toolVersion:     '0.1.0-test',
            requestedPaths:  ['src/<bad>.php'],
            format:          'html',
            failOn:          'none',
            filesDiscovered: 1,
            filesParsed:     1,
            ignoredPaths:    [],
            missingPaths:    [],
            diagnostics:     [],
            findings:        $findings,
            exitCode:        0,
            score:           $score,
            diff:            DiffResult::inactive(),
        );

        $html = (new HtmlReporter())->render($report);

        self::assertStringContainsString('<section class="verdict">', $html);
        self::assertStringContainsString('pillar grades', $html);
        self::assertStringContainsString('top offenders', $html);
        self::assertStringContainsString('<h2 class="section-head">pillar grades', $html);
        self::assertStringContainsString('<h3 class="rule">docs.missing-public-phpdoc</h3>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        self::assertStringContainsString('src/&lt;bad&gt;.php', $html);
        self::assertStringNotContainsString('<script>alert("x")</script>', $html);
        self::assertStringNotContainsString('fonts.googleapis.com', $html);
        self::assertStringNotContainsString('mutation', $html);
    }

    /**
     * Verify HTML reporter omits mutation visualization.
     *
     * @return void No return value.
     */
    public function testHtmlReporterOmitsMutationVisualization(): void
    {
        $score  = (new ScoreCalculator())->calculate([], null, DiffResult::inactive());
        $report = new AnalysisReport(
            toolVersion:     '0.1.0-test',
            requestedPaths:  ['src'],
            format:          'html',
            failOn:          'none',
            filesDiscovered: 0,
            filesParsed:     0,
            ignoredPaths:    [],
            missingPaths:    [],
            diagnostics:     [],
            findings:        [],
            exitCode:        0,
            score:           $score,
            diff:            DiffResult::inactive(),
        );

        $html = (new HtmlReporter())->render($report);

        self::assertStringContainsString('cyclomatic complexity · flagged methods', $html);
        self::assertStringNotContainsString('mutation', $html);
        self::assertStringNotContainsString('MSI', $html);
    }

    /**
     * Verify HTML reporter uses canonical severity vocabulary and accessible markup.
     *
     * @return void No return value.
     */
    public function testHtmlReporterUsesCanonicalSeverityVocabularyAndAccessibleMarkup(): void
    {
        $html = (new HtmlReporter('/workspace/project'))->render($this->sampleReport());

        self::assertStringContainsString('<div class="lbl">errors</div>', $html);
        self::assertStringContainsString('<div class="lbl">warnings</div>', $html);
        self::assertStringContainsString('<div class="lbl">advisories</div>', $html);
        self::assertStringContainsString('<span class="key">advisories</span>', $html);
        self::assertStringContainsString('<span class="key">warnings</span>', $html);
        self::assertStringContainsString('<span class="key">errors</span>', $html);
        self::assertStringNotContainsString('blocked', $html);
        self::assertStringNotContainsString('warned', $html);
        self::assertStringNotContainsString('noted', $html);
        self::assertStringNotContainsString('errors/warnings', $html);
        self::assertStringContainsString('<div class="num note">1</div><div class="lbl">advisories</div>', $html);
        self::assertStringNotContainsString('num ok', $html);
        self::assertStringContainsString('<th scope="col">file</th>', $html);
        self::assertStringContainsString('<th scope="col" class="num">cyclo</th>', $html);
        self::assertStringContainsString('<h2 class="section-head">flagged findings', $html);
        self::assertStringContainsString('<h3 class="rule">docs.missing-public-phpdoc</h3>', $html);
        self::assertStringContainsString('2 findings at warning or error severity across 2 pillars.', $html);
        self::assertStringContainsString('1 method exceeds CC 10 (1 in 11-15, 0 in 16-20, 0 at 21+).', $html);
        self::assertStringContainsString('<span class="loc-link" tabindex="0" data-path="src/Example.php:9">src/Example.php:9</span>', $html);
    }

    /**
     * Verify HTML reporter renders diagnostics when present.
     *
     * @return void No return value.
     */
    public function testHtmlReporterRendersDiagnosticsWhenPresent(): void
    {
        $html = (new HtmlReporter())->render($this->report([], [
            new RunDiagnostic(
                type:    'usage-error',
                message: '--report-interactive must be true or false.',
                path:    'tests/<bad>.php',
            ),
        ]));

        self::assertStringContainsString('<section class="diagnostics">', $html);
        self::assertStringContainsString('<h2 class="section-head">diagnostics', $html);
        self::assertStringContainsString('<span class="diagnostic-type">usage-error</span>', $html);
        self::assertStringContainsString('--report-interactive must be true or false.', $html);
        self::assertStringContainsString('tests/&lt;bad&gt;.php', $html);
    }

    /**
     * Verify HTML reporter uses celebration subtitle when no warning or error findings exist.
     *
     * @return void No return value.
     */
    public function testHtmlReporterUsesCelebrationSubtitleWhenNoWarningOrErrorFindingsExist(): void
    {
        $finding = new Finding(
            ruleId:     'docs.missing-public-phpdoc',
            message:    'Public method has no PHPDoc.',
            filePath:   'src/Example.php',
            line:       9,
            severity:   Severity::Advisory,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );

        $html = (new HtmlReporter())->render($this->report([$finding]));

        self::assertStringContainsString('No warning or error findings flagged.', $html);
    }

    /**
     * Verify HTML reporter renders opt in editor links.
     *
     * @return void No return value.
     */
    public function testHtmlReporterRendersOptInEditorLinks(): void
    {
        $html = (new HtmlReporter('/workspace/project', 'vscode'))->render($this->sampleReport());

        self::assertStringContainsString(
            'href="vscode://file/workspace/project/src/Example.php:9"',
            $html,
        );
        self::assertStringContainsString(
            'href="vscode://file/workspace/project/src/Complex.php"',
            $html,
        );
        self::assertStringContainsString('data-path="src/Example.php:9"', $html);
    }

    /**
     * Verify HTML reporter renders PHP storm editor links.
     *
     * @return void No return value.
     */
    public function testHtmlReporterRendersPhpStormEditorLinks(): void
    {
        $html = (new HtmlReporter('/workspace/project', 'phpstorm'))->render($this->sampleReport());

        self::assertStringContainsString(
            'href="phpstorm://open?file=%2Fworkspace%2Fproject%2Fsrc%2FExample.php&amp;line=9"',
            $html,
        );
    }

    /**
     * Verify HTML reporter renders interactive findings only when enabled.
     *
     * @return void No return value.
     */
    public function testHtmlReporterRendersInteractiveFindingsOnlyWhenEnabled(): void
    {
        $static      = (new HtmlReporter('/workspace/project'))->render($this->sampleReport());
        $interactive = (new HtmlReporter('/workspace/project', 'none', true))->render($this->sampleReport());

        self::assertStringNotContainsString('finding-filters', $static);
        self::assertStringNotContainsString('data-findings-list', $static);
        self::assertStringNotContainsString('<script type="module">', $static);
        self::assertStringContainsString('class="finding-filters"', $interactive);
        self::assertStringContainsString('aria-live="polite"', $interactive);
        self::assertStringContainsString('data-findings-list', $interactive);
        self::assertStringContainsString('data-severity="warning"', $interactive);
        self::assertStringContainsString('<script type="module">', $interactive);
        self::assertStringContainsString("parts.push('severity='+orderedSeverity", $interactive);
        self::assertStringContainsString("parts.push('group='+encodeURIComponent(state.group));", $interactive);
        self::assertStringNotContainsString('localStorage', $interactive);
    }

    /**
     * Verify HTML reporter static snapshot matches fixture.
     *
     * @return void No return value.
     */
    public function testHtmlReporterStaticSnapshotMatchesFixture(): void
    {
        $html = (new HtmlReporter('/workspace/project'))->render($this->sampleReport());

        self::assertSame($this->fixture('static.html'), $html);
    }

    /**
     * Verify HTML reporter interactive snapshot matches fixture.
     *
     * @return void No return value.
     */
    public function testHtmlReporterInteractiveSnapshotMatchesFixture(): void
    {
        $html = (new HtmlReporter('/workspace/project', 'none', true))->render($this->sampleReport());

        self::assertSame($this->fixture('interactive.html'), $html);
    }

    private function sampleReport(): AnalysisReport
    {
        return $this->report([
            new Finding(
                ruleId:     'docs.missing-public-phpdoc',
                message:    'Public method has no PHPDoc.',
                filePath:   'src/Example.php',
                line:       9,
                severity:   Severity::Warning,
                pillar:     Pillar::Documentation,
                tier:       RuleTier::V01,
                confidence: Confidence::High,
            ),
            new Finding(
                ruleId:     'complexity.cyclomatic',
                message:    'Method run() has cyclomatic complexity 12.',
                filePath:   'src/Complex.php',
                line:       12,
                severity:   Severity::Error,
                pillar:     Pillar::Complexity,
                tier:       RuleTier::V01,
                confidence: Confidence::High,
                metadata:   ['complexity' => 12],
            ),
            new Finding(
                ruleId:     'modernisation.named-argument-opportunity',
                message:    'Call could use named arguments.',
                filePath:   'src/Example.php',
                line:       20,
                severity:   Severity::Advisory,
                pillar:     Pillar::Modernisation,
                tier:       RuleTier::V01,
                confidence: Confidence::Medium,
            ),
        ]);
    }

    /**
     * @param list<Finding>       $findings
     * @param list<RunDiagnostic> $diagnostics
     */
    private function report(array $findings, array $diagnostics = []): AnalysisReport
    {
        $score = (new ScoreCalculator())->calculate($findings, null, DiffResult::inactive());

        return new AnalysisReport(
            toolVersion:     '0.1.0-test',
            requestedPaths:  ['src'],
            format:          'html',
            failOn:          'none',
            filesDiscovered: 2,
            filesParsed:     2,
            ignoredPaths:    [],
            missingPaths:    [],
            diagnostics:     $diagnostics,
            findings:        $findings,
            exitCode:        0,
            score:           $score,
            diff:            DiffResult::inactive(),
        );
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/Reporting/InteractiveReport/' . $name);
        self::assertIsString($contents);

        return $contents;
    }
}
