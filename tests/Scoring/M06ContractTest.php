<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Scoring;

use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Results\Scoring\Grade;
use GruffPhp\Results\Scoring\PillarScore;
use GruffPhp\Results\Scoring\ScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the behaviours the ratified family scoring contract fixes.
 *
 * The cross-port suite (`family-check --suite scoring`) proves the same properties for all five ports
 * at once, but it runs from the specification repository and needs every port built. These tests fail
 * here, in gruff-php's own gate, the moment one of them breaks.
 */
final class M06ContractTest extends TestCase
{
    /** Evaluated-file denominator every fixture scores against; ten keeps the derived numbers legible. */
    private const EVALUATED_FILES = 10;

    /**
     * Builds one finding with an explicit weight so a test can state what it expects.
     *
     * @param string      $ruleId - Native rule identifier, the ratified attribution key.
     * @param Pillar      $pillar - Pillar the finding lands in.
     * @param Severity    $severity - Severity driving the ratified weight.
     * @param Confidence  $confidence - Confidence multiplying that weight.
     * @param string      $filePath - Project-relative path.
     * @param string|null $symbol - Qualified symbol, or null for a file-level finding.
     * @param int|null    $line - Reported line, which the cluster key deliberately ignores.
     *
     * @return Finding - One finding ready to score.
     */
    private function finding(
        string      $ruleId,
        Pillar      $pillar,
        Severity    $severity,
        Confidence  $confidence = Confidence::High,
        string      $filePath = 'src/A.php',
        ?string     $symbol = null,
        ?int        $line = 1,
    ): Finding {
        return new Finding(
            ruleId:     $ruleId,
            message:    $ruleId . ' fired.',
            filePath:   $filePath,
            line:       $line,
            severity:   $severity,
            pillar:     $pillar,
            tier:       RuleTier::V01,
            confidence: $confidence,
            symbol:     $symbol,
        );
    }

    /**
     * Duplicating a project must not move its grade.
     *
     * This is the property the ratified shape exists to deliver: duplication doubles the findings and
     * the evaluated files together, so the density they make is unchanged. The retired absolute-sum
     * shape failed it - a 4x duplication of identical code cost gruff-php nearly ten composite points.
     */
    public function testScaleIsNotAnAutomaticPenalty(): void
    {
        $single     = [$this->finding('naming.one', Pillar::Naming, Severity::Warning, filePath: 'src/A.php')];
        $doubled    = [...$single, $this->finding('naming.one', Pillar::Naming, Severity::Warning, filePath: 'src/B.php')];
        $quadrupled = [
            ...$doubled,
            $this->finding('naming.one', Pillar::Naming, Severity::Warning, filePath: 'src/C.php'),
            $this->finding('naming.one', Pillar::Naming, Severity::Warning, filePath: 'src/D.php'),
        ];

        $base = (new ScoreCalculator())->calculate($single, self::EVALUATED_FILES, null, DiffResult::inactive());
        self::assertNotNull($base->composite);

        $scaled = (new ScoreCalculator())->calculate($doubled, self::EVALUATED_FILES * 2, null, DiffResult::inactive());
        self::assertNotNull($scaled->composite);
        self::assertSame($base->composite->score, $scaled->composite->score);

        $wide = (new ScoreCalculator())->calculate($quadrupled, self::EVALUATED_FILES * 4, null, DiffResult::inactive());
        self::assertNotNull($wide->composite);
        self::assertSame($base->composite->score, $wide->composite->score);
    }

    /**
     * Adding a finding without adding a file can only worsen its own pillar.
     */
    public function testMonotonicityAtAFixedDenominator(): void
    {
        $before = (new ScoreCalculator())->calculate(
            [$this->finding('security.one', Pillar::Security, Severity::Warning)],
            self::EVALUATED_FILES,
            null,
            DiffResult::inactive(),
        );
        $after = (new ScoreCalculator())->calculate(
            [
                $this->finding('security.one', Pillar::Security, Severity::Warning),
                $this->finding('security.two', Pillar::Security, Severity::Error),
            ],
            self::EVALUATED_FILES,
            null,
            DiffResult::inactive(),
        );

        $securityBefore = $this->pillar($before->pillars, 'security');
        $securityAfter  = $this->pillar($after->pillars, 'security');

        self::assertNotNull($securityBefore->grade);
        self::assertNotNull($securityAfter->grade);
        self::assertLessThan($securityBefore->grade->score, $securityAfter->grade->score);

        self::assertNotNull($before->composite);
        self::assertNotNull($after->composite);
        self::assertLessThan($before->composite->score, $after->composite->score);

        // A pillar that gained no finding must not move, or the composite couples unrelated areas.
        $documentationBefore = $this->pillar($before->pillars, 'documentation');
        $documentationAfter  = $this->pillar($after->pillars, 'documentation');
        self::assertNotNull($documentationBefore->grade);
        self::assertNotNull($documentationAfter->grade);
        self::assertSame($documentationBefore->grade->score, $documentationAfter->grade->score);
    }

    /**
     * A reachable clean pillar scores 100; a run that evaluated nothing scores nothing at all.
     */
    public function testApplicabilityKeepsNullApartFromPerfect(): void
    {
        $clean = (new ScoreCalculator())->calculate([], self::EVALUATED_FILES, null, DiffResult::inactive());

        foreach ($clean->pillars as $pillar) {
            // The mutation pillar is graded from an Infection report, which this run has none of.
            if (!$pillar->applicable) {
                continue;
            }

            self::assertNotNull($pillar->grade, sprintf('pillar %s has no grade', $pillar->pillar));
            self::assertSame(100.0, $pillar->grade->score, sprintf('reachable clean pillar %s', $pillar->pillar));
        }

        $nothing = (new ScoreCalculator())->calculate([], 0, null, DiffResult::inactive());
        self::assertNull($nothing->composite);
        self::assertSame(0, $nothing->evaluatedFiles);

        foreach ($nothing->pillars as $pillar) {
            self::assertNull($pillar->grade, sprintf('pillar %s scored with nothing evaluated', $pillar->pillar));
        }
    }

    /**
     * Ties round away from zero, matching the four sibling ports.
     */
    public function testSerializationRoundsToTwoDecimalsAwayFromZero(): void
    {
        self::assertSame(53.13, Grade::fromScore(53.125)->score);
        self::assertSame(97.68, Grade::fromScore(97.681818)->score);
        self::assertSame(100.0, Grade::fromScore(100.0)->score);
    }

    /**
     * Correlated findings on one symbol bill once, whatever lines they report.
     *
     * Correlated rules disagree about which line to report, so a line in the cluster key would split
     * one root cause into two and bill it twice.
     */
    public function testClusteringKeysOnSymbolWithoutLineIdentity(): void
    {
        $report = (new ScoreCalculator())->calculate(
            [
                $this->finding('size.method-length', Pillar::Size, Severity::Warning, symbol: 'A::run()', line: 1),
                $this->finding('complexity.cyclomatic', Pillar::Complexity, Severity::Warning, symbol: 'A::run()', line: 9),
            ],
            self::EVALUATED_FILES,
            null,
            DiffResult::inactive(),
        );

        // One warning weighs 4, so the cluster bills 4 across two members: 2 each.
        self::assertSame(2.0, $this->pillar($report->pillars, 'size')->penalty);
        self::assertSame(2.0, $this->pillar($report->pillars, 'complexity')->penalty);

        self::assertCount(1, $report->clusters);
        self::assertSame(2, $report->clusters[0]['findings']);
        self::assertSame(4.0, $report->clusters[0]['weight']);
        self::assertSame(['complexity.cyclomatic', 'size.method-length'], $report->clusters[0]['ruleIds']);
        // The ratified key carries no line identity, so the published cluster must not name one.
        self::assertArrayNotHasKey('line', $report->clusters[0]);

        $distinct = (new ScoreCalculator())->calculate(
            [
                $this->finding('size.method-length', Pillar::Size, Severity::Warning, symbol: 'A::run()', line: 1),
                $this->finding('complexity.cyclomatic', Pillar::Complexity, Severity::Warning, symbol: 'A::walk()', line: 9),
            ],
            self::EVALUATED_FILES,
            null,
            DiffResult::inactive(),
        );
        self::assertSame([], $distinct->clusters);
    }

    /**
     * Every rule that produced a finding owes exactly one row, sorted by its native identifier.
     */
    public function testRuleAttributionIsKeyedByNativeRuleId(): void
    {
        $report = (new ScoreCalculator())->calculate(
            [
                $this->finding('naming.b-rule', Pillar::Naming, Severity::Advisory),
                $this->finding('naming.a-rule', Pillar::Naming, Severity::Warning, Confidence::Medium),
                $this->finding('naming.a-rule', Pillar::Naming, Severity::Warning, Confidence::Medium, 'src/B.php'),
            ],
            self::EVALUATED_FILES,
            null,
            DiffResult::inactive(),
        );

        self::assertSame(['naming.a-rule', 'naming.b-rule'], array_column($report->ruleAttribution, 'ruleId'));
        // Two warnings at medium confidence weigh 4 * 0.75 each.
        self::assertSame(2, $report->ruleAttribution[0]['findings']);
        self::assertSame(6.0, $report->ruleAttribution[0]['weight']);
        self::assertSame(1, $report->ruleAttribution[1]['findings']);
        self::assertSame(1.0, $report->ruleAttribution[1]['weight']);
    }

    /**
     * The composite a person reads and the one a script reads come from one calculation.
     */
    public function testMachineViewPublishesTheScoredComposite(): void
    {
        $report = (new ScoreCalculator())->calculate(
            [$this->finding('complexity.cyclomatic', Pillar::Complexity, Severity::Warning)],
            self::EVALUATED_FILES,
            null,
            DiffResult::inactive(),
        );
        self::assertNotNull($report->composite);

        $payload = $report->toArray();
        self::assertSame(['score' => $report->composite->score, 'grade' => $report->composite->letter], $payload['composite']);
        self::assertSame(self::EVALUATED_FILES, $payload['evaluatedFiles']);
        self::assertSame($report->scoredPillars, $payload['scoredPillars']);
    }

    /**
     * Finds one pillar row by name, failing the test rather than returning null.
     *
     * @param list<PillarScore> $pillars - Published pillar rows.
     * @param string            $name - Pillar to find.
     *
     * @return PillarScore - The named pillar row.
     */
    private function pillar(array $pillars, string $name): PillarScore
    {
        foreach ($pillars as $pillar) {
            if ($pillar->pillar === $name) {
                return $pillar;
            }
        }

        self::fail(sprintf('pillar %s absent from the published set', $name));
    }
}
