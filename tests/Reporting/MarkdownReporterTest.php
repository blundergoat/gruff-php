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
use GruffPhp\Reporting\MarkdownReporter;
use GruffPhp\Scoring\Grade;
use GruffPhp\Scoring\PillarScore;
use GruffPhp\Scoring\ScoreCalculator;
use GruffPhp\Scoring\ScoreReport;
use PHPUnit\Framework\TestCase;

/**
 * Covers Markdown reporter rendering: the canonical cross-port Pillars table
 * (7 columns: pillar, grade, score, findings, advisory, warning, error) shared
 * with the Go/Python/Rust/TypeScript reporters, including header shape, score
 * formatting, sort order, and graceful handling of empty / non-applicable
 * pillar inputs.
 */
final class MarkdownReporterTest extends TestCase
{
    /**
     * Verify Markdown reporter renders the canonical 7-column Pillars header
     * and separator near the top of the report, before the Findings section.
     *
     * @return void
     */
    public function testMarkdownReporterRendersCanonicalPillarsHeader(): void
    {
        $findings = [
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
        ];

        $markdown = (new MarkdownReporter())->render($this->report($findings));

        self::assertStringContainsString('## Pillars', $markdown);
        self::assertStringContainsString(
            '| Pillar | Grade | Score | Findings | Advisory | Warning | Error |',
            $markdown,
        );
        self::assertStringContainsString(
            '| --- | --- | ---: | ---: | ---: | ---: | ---: |',
            $markdown,
        );

        $pillarsAt  = strpos($markdown, '## Pillars');
        $findingsAt = strpos($markdown, '## Findings');
        self::assertIsInt($pillarsAt);
        self::assertIsInt($findingsAt);
        self::assertLessThan($findingsAt, $pillarsAt);
    }

    /**
     * Verify Markdown reporter renders pillar rows sorted by findings DESC,
     * then pillar ASC, with scores formatted to two decimals, mirroring the
     * cross-port canonical block. The expected block is asserted as a single
     * string so the row order is verified by exact match.
     *
     * @return void
     */
    public function testMarkdownReporterSortsPillarsByFindingsDescThenNameAsc(): void
    {
        $scoreReport = new ScoreReport(
            composite: new Grade(70.0, 'C'),
            pillars:   [
                new PillarScore('size', true, new Grade(90.5, 'A'), 2, 1, 1, 0, 9.5),
                new PillarScore('documentation', true, new Grade(60.123, 'D'), 5, 3, 2, 0, 39.877),
                new PillarScore('complexity', true, new Grade(75.0, 'C'), 2, 0, 1, 1, 25.0),
                new PillarScore('naming', true, new Grade(100.0, 'A'), 0, 0, 0, 0, 0.0),
            ],
            topOffenders:           [],
            complexityDistribution: [],
            scope:                  'fixture',
            explanation:            'fixture score',
        );

        $markdown = (new MarkdownReporter())->render($this->report([], $scoreReport));

        $expectedBlock = implode("\n", [
            '## Pillars',
            '',
            '| Pillar | Grade | Score | Findings | Advisory | Warning | Error |',
            '| --- | --- | ---: | ---: | ---: | ---: | ---: |',
            '| documentation | D | 60.12 | 5 | 3 | 2 | 0 |',
            '| complexity | C | 75.00 | 2 | 0 | 1 | 1 |',
            '| size | A | 90.50 | 2 | 1 | 1 | 0 |',
            '| naming | A | 100.00 | 0 | 0 | 0 | 0 |',
        ]);

        self::assertStringContainsString($expectedBlock, $markdown);
    }

    /**
     * Verify Markdown reporter skips non-applicable pillars (for example the
     * mutation pillar when no Infection report is supplied) so the canonical
     * table never carries rows the analyser flagged as inapplicable.
     *
     * @return void
     */
    public function testMarkdownReporterOmitsNonApplicablePillars(): void
    {
        $scoreReport = new ScoreReport(
            composite: new Grade(82.0, 'B'),
            pillars:   [
                new PillarScore('mutation', false, null, 0, 0, 0, 0, 0.0),
                new PillarScore('documentation', true, new Grade(82.0, 'B'), 4, 2, 2, 0, 18.0),
            ],
            topOffenders:           [],
            complexityDistribution: [],
            scope:                  'fixture',
            explanation:            'fixture score',
        );

        $markdown = (new MarkdownReporter())->render($this->report([], $scoreReport));

        self::assertStringContainsString('| documentation | B | 82.00 | 4 | 2 | 2 | 0 |', $markdown);
        self::assertStringNotContainsString('| mutation |', $markdown);
    }

    /**
     * Verify Markdown reporter renders an `_(none)_` placeholder row when no
     * applicable pillars are available, so the column layout stays parsable by
     * downstream PR-comment scrapers even on a zero-pillar run.
     *
     * @return void
     */
    public function testMarkdownReporterRendersPlaceholderRowWhenNoApplicablePillars(): void
    {
        $scoreReport = new ScoreReport(
            composite: new Grade(100.0, 'A'),
            pillars:   [
                new PillarScore('mutation', false, null, 0, 0, 0, 0, 0.0),
            ],
            topOffenders:           [],
            complexityDistribution: [],
            scope:                  'fixture',
            explanation:            'fixture score',
        );

        $markdown = (new MarkdownReporter())->render($this->report([], $scoreReport));

        self::assertStringContainsString('| _(none)_ |  |  |  |  |  |  |', $markdown);
    }

    /**
     * Verify Markdown reporter sources pillar data from the existing
     * {@see PillarScore} entries without recomputing per-severity counts when
     * the supplied counts diverge from the raw finding list.
     *
     * @return void
     */
    public function testMarkdownReporterReusesPillarScoreSeverityCounts(): void
    {
        $scoreReport = new ScoreReport(
            composite: new Grade(50.0, 'F'),
            pillars:   [
                new PillarScore('documentation', true, new Grade(0.0, 'F'), 12, 9, 2, 1, 100.0),
            ],
            topOffenders:           [],
            complexityDistribution: [],
            scope:                  'fixture',
            explanation:            'fixture score',
        );

        $markdown = (new MarkdownReporter())->render($this->report([], $scoreReport));

        self::assertStringContainsString('| documentation | F | 0.00 | 12 | 9 | 2 | 1 |', $markdown);
    }

    /**
     * Verify Markdown reporter renders `n/a` grade and score cells when the
     * supplied {@see PillarScore} has no grade attached.
     *
     * @return void
     */
    public function testMarkdownReporterRendersNotApplicableGradeWhenGradeMissing(): void
    {
        $scoreReport = new ScoreReport(
            composite: new Grade(100.0, 'A'),
            pillars:   [
                new PillarScore('documentation', true, null, 0, 0, 0, 0, 0.0),
            ],
            topOffenders:           [],
            complexityDistribution: [],
            scope:                  'fixture',
            explanation:            'fixture score',
        );

        $markdown = (new MarkdownReporter())->render($this->report([], $scoreReport));

        self::assertStringContainsString('| documentation | n/a | n/a | 0 | 0 | 0 | 0 |', $markdown);
    }

    /**
     * Verify Markdown reporter escapes pipe characters in pillar identifiers
     * so a future pillar name carrying `|` cannot break the surrounding row.
     *
     * @return void
     */
    public function testMarkdownReporterEscapesPipeCharactersInPillarCells(): void
    {
        $scoreReport = new ScoreReport(
            composite: new Grade(90.0, 'A'),
            pillars:   [
                new PillarScore('a|b', true, new Grade(0.0, 'F'), 1, 0, 0, 1, 100.0),
            ],
            topOffenders:           [],
            complexityDistribution: [],
            scope:                  'fixture',
            explanation:            'fixture score',
        );

        $markdown = (new MarkdownReporter())->render($this->report([], $scoreReport));

        self::assertStringContainsString('| a\\|b | F | 0.00 | 1 | 0 | 0 | 1 |', $markdown);
    }

    /**
     * Build an analysis report fixture for renderer assertions; either uses
     * the supplied {@see ScoreReport} or derives one from the supplied
     * findings via {@see ScoreCalculator}.
     *
     * @param list<Finding>    $findings
     * @param ScoreReport|null $score
     * @return AnalysisReport
     */
    private function report(array $findings, ?ScoreReport $score = null): AnalysisReport
    {
        $score ??= (new ScoreCalculator())->calculate($findings, null, DiffResult::inactive());

        return new AnalysisReport(
            toolVersion:     '0.1.0-test',
            requestedPaths:  ['src'],
            format:          'markdown',
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
    }
}
