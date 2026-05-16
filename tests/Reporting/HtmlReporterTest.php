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
use GruffPhp\Scoring\FileScore;
use GruffPhp\Scoring\Grade;
use GruffPhp\Scoring\PillarScore;
use GruffPhp\Scoring\ScoreCalculator;
use GruffPhp\Scoring\ScoreReport;
use PHPUnit\Framework\TestCase;

/**
 * Covers HtmlReporterTest behavior.
 */
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
        self::assertStringContainsString('score drivers', $html);
        self::assertStringContainsString('Mutation is omitted when no Infection report is supplied.', $html);
        self::assertStringNotContainsString('<div class="name">mutation</div>', $html);
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
        self::assertStringContainsString('Mutation is omitted when no Infection report is supplied.', $html);
        self::assertStringNotContainsString('<div class="name">mutation</div>', $html);
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
        self::assertStringContainsString('<div class="score-context-title">score drivers</div>', $html);
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

    /**
     * Verify missing score default path and active diff labels render safely.
     *
     * @return void No return value.
     */
    public function testHtmlReporterHandlesMissingScoreDefaultPathAndActiveDiffScope(): void
    {
        $report = new AnalysisReport(
            toolVersion:     '0.1.0-test',
            requestedPaths:  [],
            format:          'html',
            failOn:          'warning',
            filesDiscovered: 0,
            filesParsed:     0,
            ignoredPaths:    [],
            missingPaths:    [],
            diagnostics:     [],
            findings:        [],
            exitCode:        0,
            score:           null,
            diff:            new DiffResult(true, 'diff', 'main', [], ['src/A.php', 'src/B.php'], 'Diff active.'),
        );

        $html = (new HtmlReporter())->render($report);

        self::assertStringContainsString('<title>gruff-php inspection report - n/a</title>', $html);
        self::assertStringContainsString('<div class="grade-letter">n/a</div>', $html);
        self::assertStringContainsString('<div class="grade-score">n/a</div>', $html);
        self::assertStringContainsString('<span class="label">paths</span><span class="val">.</span>', $html);
        self::assertStringContainsString('<span class="label">scope</span><span class="val">diff · 2 changed files</span>', $html);
    }

    /**
     * Verify major sections keep their document order around diagnostics.
     *
     * @return void No return value.
     */
    public function testHtmlReporterKeepsMastheadDiagnosticsAndVerdictInOrder(): void
    {
        $html = (new HtmlReporter())->render($this->report([], [
            new RunDiagnostic(type: 'parse-error', message: 'broken', filePath: 'src/Broken.php', line: 7),
        ]));

        $masthead    = strpos($html, '<header class="masthead">');
        $diagnostics = strpos($html, '<section class="diagnostics">');
        $verdict     = strpos($html, '<section class="verdict">');

        self::assertIsInt($masthead);
        self::assertIsInt($diagnostics);
        self::assertIsInt($verdict);
        self::assertLessThan($diagnostics, $masthead);
        self::assertLessThan($verdict, $diagnostics);
        self::assertStringContainsString('</div></section><section class="verdict">', $html);
    }

    /**
     * Verify diagnostic locations prefer parsed file paths with lines over input paths.
     *
     * @return void No return value.
     */
    public function testHtmlReporterDiagnosticLocationsPreferFilePathLineThenPath(): void
    {
        $html = (new HtmlReporter())->render($this->report([], [
            new RunDiagnostic(type: 'parse-error', message: 'bad php', filePath: 'src/File.php', line: 12, path: 'src'),
            new RunDiagnostic(type: 'usage-error', message: 'bad path', path: 'src/Missing.php'),
        ]));

        self::assertStringContainsString('src/File.php:12', $html);
        self::assertStringContainsString('src/Missing.php', $html);
        self::assertStringNotContainsString('src/Missing.php:', $html);
    }

    /**
     * Verify custom score reports render empty and distribution edge cases.
     *
     * @return void No return value.
     */
    public function testHtmlReporterRendersCustomScoreReportEdgeCases(): void
    {
        $score = new ScoreReport(
            composite: new Grade(88.25, 'B'),
            pillars:   [
                new PillarScore('Mutation', true, new Grade(99.0, 'A'), 1, 0, 0, 1, 1.0),
                new PillarScore('documentation', true, null, 0, 0, 0, 0, 0.0),
            ],
            topOffenders:           [],
            complexityDistribution: ['0-5' => 1, '11-15' => 2],
            scope:                  'fixture',
            explanation:            'custom score',
        );
        $report = $this->report([], [], $score);

        $html = (new HtmlReporter())->render($report);

        self::assertStringContainsString('No offenders found.', $html);
        self::assertStringNotContainsString('<div class="name">Mutation</div>', $html);
        self::assertStringContainsString('<li>custom score</li>', $html);
        self::assertStringContainsString('<div class="grade n">n/a</div>', $html);
        self::assertStringContainsString('2 methods exceed CC 10 (2 in 11-15, 0 in 16-20, 0 at 21+).', $html);
        self::assertStringContainsString('style="height:50%;"', $html);
        self::assertStringContainsString('style="height:100%;"', $html);
    }

    /**
     * Verify custom score reports render offender metrics in fixed column order.
     *
     * @return void No return value.
     */
    public function testHtmlReporterRendersOffenderMetricColumnsInOrder(): void
    {
        $score = new ScoreReport(
            composite:    new Grade(72.0, 'C'),
            pillars:      [],
            topOffenders: [
                new FileScore('src/Metrics.php', new Grade(72.0, 'C'), 4, 1, 2, 1, 28.0, 3, 5, 8, null),
            ],
            complexityDistribution: ['0-5' => 1, '11-15' => 2, '16-20' => 3],
            scope:                  'fixture',
            explanation:            'custom score',
        );

        $html = (new HtmlReporter())->render($this->report([], [], $score));

        self::assertStringContainsString(
            '<td class="file-path"><span class="loc-link" tabindex="0" data-path="src/Metrics.php">src/Metrics.php</span></td><td class="num">3</td><td class="num">5</td><td class="num">8</td><td class="num">4</td>',
            $html,
        );
        self::assertStringContainsString('5 methods exceed CC 10 (2 in 11-15, 3 in 16-20, 0 at 21+).', $html);
        self::assertStringContainsString('style="height:33%;"', $html);
        self::assertStringContainsString('style="height:67%;"', $html);
    }

    /**
     * Verify interactive pillar select size is bounded.
     *
     * @return void No return value.
     */
    public function testHtmlReporterInteractivePillarSelectSizeIsBounded(): void
    {
        $singlePillar = new Finding(
            ruleId:     'docs.missing-public-phpdoc',
            message:    'Public method has no PHPDoc.',
            filePath:   'src/Example.php',
            line:       9,
            severity:   Severity::Warning,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );
        $manyPillars = [
            $singlePillar,
            new Finding('complexity.cyclomatic', 'Complex.', 'src/A.php', 1, Severity::Warning, Pillar::Complexity, RuleTier::V01, Confidence::High),
            new Finding('security.eval', 'Security.', 'src/B.php', 1, Severity::Warning, Pillar::Security, RuleTier::V01, Confidence::High),
            new Finding('naming.short-variable', 'Naming.', 'src/C.php', 1, Severity::Warning, Pillar::Naming, RuleTier::V01, Confidence::High),
            new Finding('size.method-length', 'Size.', 'src/D.php', 1, Severity::Warning, Pillar::Size, RuleTier::V01, Confidence::High),
            new Finding('dead-code.unused-private-method', 'Dead.', 'src/E.php', 1, Severity::Warning, Pillar::DeadCode, RuleTier::V01, Confidence::High),
            new Finding('modernisation.final-class', 'Modern.', 'src/F.php', 1, Severity::Warning, Pillar::Modernisation, RuleTier::V01, Confidence::High),
        ];

        $singleHtml = (new HtmlReporter('/workspace/project', 'none', true))->render($this->report([$singlePillar]));
        $manyHtml   = (new HtmlReporter('/workspace/project', 'none', true))->render($this->report($manyPillars));

        self::assertStringContainsString('<label>Pillar<select name="pillar" multiple size="2">', $singleHtml);
        self::assertStringContainsString('<label>Pillar<select name="pillar" multiple size="6">', $manyHtml);
    }

    /**
     * Build a sample analysis report for renderer assertions.
     *
     * @return AnalysisReport Fixture value.
     */
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
     * @param ScoreReport|null    $score
     * @return AnalysisReport Report fixture.
     */
    private function report(array $findings, array $diagnostics = [], ?ScoreReport $score = null): AnalysisReport
    {
        $score ??= (new ScoreCalculator())->calculate($findings, null, DiffResult::inactive());

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

    /**
     * Load an expected fixture snapshot.
     *
     * @param string $name Fixture name.
     * @return string Fixture value.
     */
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/Reporting/InteractiveReport/' . $name);
        self::assertIsString($contents);

        return $contents;
    }
}
