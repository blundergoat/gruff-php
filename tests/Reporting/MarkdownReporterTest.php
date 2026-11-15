<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Output\Reporter\MarkdownReporter;
use GruffPhp\Results\Scoring\Grade;
use GruffPhp\Results\Scoring\PillarScore;
use GruffPhp\Results\Scoring\ScoreCalculator;
use GruffPhp\Results\Scoring\ScoreReport;
use PHPUnit\Framework\TestCase;

/**
 * Tests the seven-column Markdown Pillars header, separator, and section order.
 * Covers row ordering, score formatting, supplied severity counts, and omitted or ungraded pillars.
 * Checks the placeholder row, escaped pillar identifiers, and output equivalence with action metadata.
 */
final class MarkdownReporterTest extends TestCase
{
    /**
     * Verify the seven-column Pillars header and separator are present and the Pillars heading precedes Findings.
     *
     * @return void - Asserts header and separator text plus heading order.
     */
    public function testMarkdownReporterRendersCanonicalPillarsHeader(): void
    {
        $findings = [
            new Finding(
                ruleId:     'docs.missing-phpdoc',
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
     * Verify pillar rows sort by descending finding count, then ascending name, with two-decimal scores.
     * The expected table block must occur contiguously in the rendered report.
     *
     * @return void - Asserts the expected row order, counts, grades, and formatted scores.
     */
    public function testMarkdownReporterSortsPillarsByFindingsDescThenNameAsc(): void
    {
        $scoreReport = new ScoreReport(
            clusters:               [],
            ruleAttribution:        [],
            evaluatedFiles:         10,
            scoredPillars:          [],
            composite:              new Grade(70.0, 'C'),
            pillars:                [
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
     * Verify the supplied inapplicable mutation pillar is omitted while the applicable documentation row is rendered.
     *
     * @return void - Asserts the documentation row is present and the mutation row is absent.
     */
    public function testMarkdownReporterOmitsNonApplicablePillars(): void
    {
        $scoreReport = new ScoreReport(
            clusters:               [],
            ruleAttribution:        [],
            evaluatedFiles:         10,
            scoredPillars:          [],
            composite:              new Grade(82.0, 'B'),
            pillars:                [
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
     * Verify a placeholder row is rendered when the only supplied pillar is inapplicable.
     *
     * @return void - Asserts the seven-cell placeholder row is present.
     */
    public function testMarkdownReporterRendersPlaceholderRowWhenNoApplicablePillars(): void
    {
        $scoreReport = new ScoreReport(
            clusters:               [],
            ruleAttribution:        [],
            evaluatedFiles:         10,
            scoredPillars:          [],
            composite:              new Grade(100.0, 'A'),
            pillars:                [
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
     * Verify Markdown uses supplied {@see PillarScore} counts instead of recomputing them from empty findings.
     *
     * @return void - Asserts the supplied finding and severity counts appear despite an empty finding list.
     */
    public function testMarkdownReporterReusesPillarScoreSeverityCounts(): void
    {
        $scoreReport = new ScoreReport(
            clusters:               [],
            ruleAttribution:        [],
            evaluatedFiles:         10,
            scoredPillars:          [],
            composite:              new Grade(50.0, 'F'),
            pillars:                [
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
     * Verify Markdown renders n/a grade and score cells when the supplied {@see PillarScore} has no grade attached.
     *
     * @return void - Asserts both grade and score cells contain n/a for the ungraded pillar.
     */
    public function testMarkdownReporterRendersNotApplicableGradeWhenGradeMissing(): void
    {
        $scoreReport = new ScoreReport(
            clusters:               [],
            ruleAttribution:        [],
            evaluatedFiles:         10,
            scoredPillars:          [],
            composite:              new Grade(100.0, 'A'),
            pillars:                [
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
     * Verify the pipe in the supplied pillar identifier is escaped in the rendered row.
     *
     * @return void - Asserts the expected row contains the escaped a|b identifier.
     */
    public function testMarkdownReporterEscapesPipeCharactersInPillarCells(): void
    {
        $scoreReport = new ScoreReport(
            clusters:               [],
            ruleAttribution:        [],
            evaluatedFiles:         10,
            scoredPillars:          [],
            composite:              new Grade(90.0, 'A'),
            pillars:                [
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
     * Verify action metadata leaves the rendered Markdown unchanged for otherwise identical findings.
     *
     * @return void - Asserts equality of the complete renders for the plain and classified findings.
     */
    public function testRemediationActionMetadataDoesNotChangeMarkdownOutput(): void
    {
        $plain = new Finding(
            ruleId:      'docs.regex-comment',
            message:     'A regular expression needs a purpose comment.',
            filePath:    'src/Example.php',
            line:        12,
            severity:    Severity::Advisory,
            pillar:      Pillar::Documentation,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Add a purpose comment.',
        );
        $serialized             = $plain->toArray();
        $serialized['metadata'] = RemediationAction::Apply->metadata();
        $classified             = Finding::fromArray($serialized);
        $reporter               = new MarkdownReporter();

        self::assertSame(
            $reporter->render($this->report([$plain])),
            $reporter->render($this->report([$classified])),
        );
    }

    /**
     * Build a Markdown fixture from the supplied {@see ScoreReport} or a score derived from the findings via {@see ScoreCalculator}.
     *
     * @param list<Finding>    $findings - Findings to render in the markdown fixture report.
     * @param ScoreReport|null $score - Precomputed score to use, or null to derive one from the findings.
     *
     * @return AnalysisReport - a markdown-format fixture wired with the given findings and resolved score, ready to render
     */
    private function report(array $findings, ?ScoreReport $score = null): AnalysisReport
    {
        $score ??= (new ScoreCalculator())->calculate($findings, 10, null, DiffResult::inactive());

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
