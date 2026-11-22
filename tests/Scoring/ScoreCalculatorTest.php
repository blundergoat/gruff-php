<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Scoring;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Results\Mutation\InfectionMutant;
use GruffPhp\Results\Mutation\InfectionReport;
use GruffPhp\Results\Mutation\MutationAnalysisResult;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\FileLengthRule;
use GruffPhp\Results\Scoring\Grade;
use GruffPhp\Results\Scoring\ScoreReport;
use GruffPhp\Results\Scoring\ScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Covers A-F grade thresholds and the presence or absence of mutation scoring.
 * Checks correlated penalties, pillar counts, and per-file metrics.
 * Verifies selected-pillar composites and per-rule score exclusions.
 */
final class ScoreCalculatorTest extends TestCase
{
    /**
     * Checks that every registered primary and secondary pillar is in STATIC_PILLARS.
     * Keeping clean catalogue pillars present prevents findings from changing the composite divisor.
     *
     * @return void - Asserts that no registered primary or secondary pillar is missing.
     */
    public function testStaticPillarsCoverEveryCataloguePillar(): void
    {
        $staticPillars   = (new \ReflectionClassConstant(ScoreCalculator::class, 'STATIC_PILLARS'))->getValue();
        $cataloguePillars = [];


        foreach (RuleRegistry::defaults()->all() as $rule) {
            $definition = $rule->definition();
            $cataloguePillars[$definition->pillar->value] = true;

            foreach ($definition->secondaryPillars as $secondaryPillar) {
                $cataloguePillars[$secondaryPillar->value] = true;
            }
        }

        self::assertIsArray($staticPillars);
        $unnamedPillars = array_filter(
            array_keys($cataloguePillars),
            static fn(string $pillar): bool => !in_array($pillar, $staticPillars, true),
        );
        self::assertSame([], array_values($unnamedPillars));
    }

    /**
     * Checks that 90/80/70/60 map to A/B/C/D and 59.99 maps to F.
     *
     * @return void - Asserts A/B/C/D at 90/80/70/60 and F at 59.99.
     */
    public function testGradeBoundariesUseSimpleAFScale(): void
    {
        self::assertSame('A', Grade::letterFor(90.0));
        self::assertSame('B', Grade::letterFor(80.0));
        self::assertSame('C', Grade::letterFor(70.0));
        self::assertSame('D', Grade::letterFor(60.0));
        self::assertSame('F', Grade::letterFor(59.99));
    }

    /**
     * Verify score report omits mutation pillar when infection data is absent.
     *
     * @return void - Asserts mutation is absent, scope is full-project, and the security pillar is graded F.
     */
    public function testScoreReportOmitsMutationPillarWhenInfectionDataIsAbsent(): void
    {
        $score = (new ScoreCalculator())->calculate([
                                                        $this->finding('docs.missing-phpdoc', Pillar::Documentation, Severity::Advisory),
                                                        $this->finding('security.dangerous-function-call', Pillar::Security, Severity::Error),
                                                    ], 10, null, DiffResult::inactive());

        self::assertLessThan(100.0, $this->composite($score)->score);
        self::assertSame('full-project', $score->scope);
        self::assertSame(
            'Each pillar scores on the density of its weighted findings per evaluated file, on a curve from 50 to 100, so a larger project is not penalised for its size; correlated size and complexity findings on one symbol share a single weight; the composite is the average of applicable pillar scores. '
            . 'Mutation is omitted when no Infection report is supplied.',
            $score->explanation,
        );
        self::assertNotContains('mutation', array_map(static fn($pillar): string => $pillar->pillar, $score->pillars));

        $security = array_values(array_filter($score->pillars, static fn($pillar): bool => $pillar->pillar === 'security'))[0] ?? null;

        self::assertNotNull($security);
        self::assertSame(1, $security->error);
        self::assertSame('F', $security->grade?->letter);
    }

    /**
     * Checks that the input rule IDs remain unchanged and no design pillar appears in the report.
     *
     * @return void - Asserts original input IDs, no design pillar, and a composite below 100.
     */
    public function testOverlappingSizeAndComplexityFindingsRemainNativeFindings(): void
    {
        $findings = [
            $this->finding('size.method-length', Pillar::Size, Severity::Warning, filePath: 'src/TooMuch.php', line: 12, endLine: 30, symbol: 'TooMuch::run()'),
            $this->finding('complexity.cognitive', Pillar::Complexity, Severity::Warning, filePath: 'src/TooMuch.php', line: 14, endLine: 28, symbol: 'TooMuch::run()'),
            $this->finding('complexity.nesting-depth', Pillar::Complexity, Severity::Warning, filePath: 'src/Other.php', line: 9, symbol: 'Other::run()'),
        ];

        $score = (new ScoreCalculator())->calculate($findings, 10, null, DiffResult::inactive());

        self::assertSame(['size.method-length', 'complexity.cognitive', 'complexity.nesting-depth'], array_map(
            static fn(Finding $finding): string => $finding->ruleId,
            $findings,
        ));
        self::assertArrayNotHasKey('design', $this->pillarMap($score->pillars));
        self::assertLessThan(100.0, $this->composite($score)->score);
    }

    /**
     * Checks that the same findings score higher when clustered on one symbol than when spread across symbols.
     * Both complexity findings and the size finding still contribute to their pillar counts.
     *
     * @return void - Asserts a higher clustered composite, two complexity findings, and one size finding.
     */
    public function testCorrelatedFindingsOnOneSymbolShareOnePenalty(): void
    {
        $clustered = (new ScoreCalculator())->calculate([
                                                            $this->finding('size.method-length', Pillar::Size, Severity::Warning, filePath: 'src/Big.php', line: 10, symbol: 'Big::run()'),
                                                            $this->finding('complexity.cognitive', Pillar::Complexity, Severity::Warning, filePath: 'src/Big.php', line: 10, symbol: 'Big::run()'),
                                                            $this->finding('complexity.nesting-depth', Pillar::Complexity, Severity::Warning, filePath: 'src/Big.php', line: 10, symbol: 'Big::run()'),
                                                        ], 10, null, DiffResult::inactive());

        $spread = (new ScoreCalculator())->calculate([
                                                         $this->finding('size.method-length', Pillar::Size, Severity::Warning, filePath: 'src/Big.php', line: 10, symbol: 'Big::run()'),
                                                         $this->finding('complexity.cognitive', Pillar::Complexity, Severity::Warning, filePath: 'src/Big.php', line: 40, symbol: 'Big::other()'),
                                                         $this->finding('complexity.nesting-depth', Pillar::Complexity, Severity::Warning, filePath: 'src/Big.php', line: 70, symbol: 'Big::third()'),
                                                     ], 10, null, DiffResult::inactive());

        self::assertGreaterThan($this->composite($spread)->score, $this->composite($clustered)->score);

        $pillars = $this->pillarMap($clustered->pillars);
        self::assertSame(2, $pillars['complexity']->findings);
        self::assertSame(1, $pillars['size']->findings);
    }

    /**
     * Checks the aggregate complexity penalty for correlated error, warning and advisory findings.
     * Their combined penalty is 12.0, with all three findings still counted.
     *
     * @return void - Asserts a 12.0 penalty and three complexity findings.
     */
    public function testClusterPenaltyIsMaxMemberPenaltyDividedByCount(): void
    {
        $score = (new ScoreCalculator())->calculate([
                                                        $this->finding('complexity.nesting-depth', Pillar::Complexity, Severity::Error, filePath: 'src/Big.php', line: 10, symbol: 'Big::run()'),
                                                        $this->finding('complexity.cognitive', Pillar::Complexity, Severity::Warning, filePath: 'src/Big.php', line: 10, symbol: 'Big::run()'),
                                                        $this->finding('complexity.nesting-depth', Pillar::Complexity, Severity::Advisory, filePath: 'src/Big.php', line: 10, symbol: 'Big::run()'),
                                                    ], 10, null, DiffResult::inactive());

        $complexity = $this->pillarMap($score->pillars)['complexity'];
        self::assertEqualsWithDelta(12.0, $complexity->penalty, 0.0001);
        self::assertSame(3, $complexity->findings);
    }

    /**
     * Checks that two warnings on the same symbol share a total penalty even without line numbers.
     * The pillar penalty and the single published cluster weight must both be 4.0.
     *
     * @return void - Asserts a 4.0 complexity penalty and one cluster with weight 4.0.
     */
    public function testCorrelatedFindingsWithoutALineShareOnePenalty(): void
    {
        $score = (new ScoreCalculator())->calculate([
            $this->finding('complexity.nesting-depth', Pillar::Complexity, Severity::Warning, filePath: 'src/Big.php', line: null, symbol: 'Big::run()'),
            $this->finding('complexity.cognitive', Pillar::Complexity, Severity::Warning, filePath: 'src/Big.php', line: null, symbol: 'Big::run()'),
        ], 10, null, DiffResult::inactive());

        self::assertEqualsWithDelta(4.0, $this->pillarMap($score->pillars)['complexity']->penalty, 0.0001);
        self::assertCount(1, $score->clusters);
        self::assertSame(4.0, $score->clusters[0]['weight'] ?? null);
    }

    /**
     * Checks that a lone complexity finding and an unrelated naming finding on the same symbol retain full penalties.
     * Each contributes 4.0 to its own pillar.
     *
     * @return void - Asserts a 4.0 penalty in both the complexity and naming pillars.
     */
    public function testLoneCorrelatedAndUnrelatedFindingsKeepFullPenalty(): void
    {
        $score = (new ScoreCalculator())->calculate([
                                                        $this->finding('complexity.nesting-depth', Pillar::Complexity, Severity::Warning, filePath: 'src/Big.php', line: 10, symbol: 'Big::run()'),
                                                        $this->finding('naming.generic-method-name', Pillar::Naming, Severity::Warning, filePath: 'src/Big.php', line: 10, symbol: 'Big::run()'),
                                                    ], 10, null, DiffResult::inactive());

        $pillars = $this->pillarMap($score->pillars);
        self::assertEqualsWithDelta(4.0, $pillars['complexity']->penalty, 0.0001);
        self::assertEqualsWithDelta(4.0, $pillars['naming']->penalty, 0.0001);
    }

    /**
     * Verify score report includes mutation pillar, file mutation scores, distribution, and diff scope.
     *
     * @return void - Asserts mutation metrics, complexity buckets, diff scope, and per-file maxima.
     */
    public function testScoreReportIncludesMutationAndFileMetrics(): void
    {
        $findings               = [
            $this->finding('complexity.cyclomatic', Pillar::Complexity, Severity::Warning, filePath: 'src/A.php', line: 4, metadata: ['complexity' => 4]),
            $this->finding('complexity.cyclomatic', Pillar::Complexity, Severity::Warning, filePath: 'src/A.php', line: 8, metadata: ['complexity' => 9]),
            $this->finding('complexity.cognitive', Pillar::Complexity, Severity::Warning, filePath: 'src/A.php', line: 12, metadata: ['complexity' => 12]),
            $this->finding('size.method-length', Pillar::Size, Severity::Advisory, filePath: 'src/A.php', line: 12, metadata: ['lines' => 44]),
            $this->finding('security.dangerous-function-call', Pillar::Security, Severity::Error, filePath: 'src/B.php', line: 2),
            $this->finding('mutation.survived', Pillar::Mutation, Severity::Warning, filePath: 'src/A.php', line: 20),
        ];
        $mutationAnalysisResult = new MutationAnalysisResult(new InfectionReport(
                                                                 reportPath: 'infection-report.json',
                                                                 stats:      [
                                                                                 'totalMutantsCount'    => 3,
                                                                                 'msi'                  => 66.67,
                                                                                 'coveredCodeMsi'       => 66.67,
                                                                                 'mutationCodeCoverage' => 100.0,
                                                                             ],
                                                                 mutants:    [
                                                                                 new InfectionMutant('killed', 'src/A.php', 1, 'Plus'),
                                                                                 new InfectionMutant('escaped', 'src/A.php', 2, 'Minus'),
                                                                                 new InfectionMutant('killed', 'src/B.php', 3, 'ReturnRemoval'),
                                                                             ],
                                                             ));

        $score   = (new ScoreCalculator())->calculate($findings, 10, $mutationAnalysisResult, new DiffResult(
            active:       true,
            mode:         'unstaged',
            base:         null,
            changedLines: [],
            changedFiles: [],
            message:      'diff',
        ));
        $payload = $score->toArray();

        self::assertSame('diff', $score->scope);
        self::assertSame(['1-5' => 1, '6-10' => 1, '11-15' => 0, '16-20' => 0, '21+' => 0], $score->complexityDistribution);
        self::assertSame('diff', $payload['scope']);
        self::assertSame(
            'Each pillar scores on the density of its weighted findings per evaluated file, on a curve from 50 to 100, so a larger project is not penalised for its size; correlated size and complexity findings on one symbol share a single weight; the composite is the average of applicable pillar scores. '
            . 'Mutation uses the supplied Infection MSI as the mutation pillar score.',
            $payload['explanation'],
        );

        $pillars = [];
        foreach ($score->pillars as $pillarScore) {
            $pillars[$pillarScore->pillar] = $pillarScore->toArray();
        }

        self::assertSame([
                             'pillar' => 'mutation',
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        'applicable' => true,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    'score' => 66.67,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    'grade' => 'D',
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    'findings' => 1,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    'advisory' => 0,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    'warning' => 1,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            'error' => 0,
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            'penalty' => 33.33,
                         ], $pillars['mutation'] ?? null);

        $offenders = [];
        foreach ($score->topOffenders as $fileScore) {
            $offenders[$fileScore->filePath] = $fileScore->toArray();
        }

        self::assertSame(50.0, $offenders['src/A.php']['mutationScore'] ?? null);
        self::assertSame(100.0, $offenders['src/B.php']['mutationScore'] ?? null);
        $expectedMaxCyclomatic = 9;
        $expectedMaxCognitive  = 12;
        $expectedMaxLines      = 44;
        self::assertSame($expectedMaxCyclomatic, $offenders['src/A.php']['maxCyclomatic'] ?? null);
        self::assertSame($expectedMaxCognitive, $offenders['src/A.php']['maxCognitive'] ?? null);
        self::assertSame($expectedMaxLines, $offenders['src/A.php']['maxLines'] ?? null);
    }

    /**
     * Verify score calculation can scope composite scoring to selected pillars.
     *
     * @return void - Asserts only the selected pillars are reported and their composite grade is C.
     */
    public function testScoreReportCanScopeCompositeToSelectedPillars(): void
    {
        $score = (new ScoreCalculator())->calculate(
                          [
                              $this->finding('security.dangerous-function-call', Pillar::Security, Severity::Error),
                              $this->finding('security.unsafe-unserialize', Pillar::Security, Severity::Error),
                          ],
                          10,
                          null,
                          DiffResult::inactive(),
            scorePillars: [Pillar::Security, Pillar::SensitiveData],
        );

        $pillars = array_map(static fn($pillar): string => $pillar->pillar, $score->pillars);

        self::assertSame(['security', 'sensitive-data'], $pillars);
        self::assertSame('C', $this->composite($score)->letter);
    }

    /**
     * Checks that excluding the file-length rule changes the composite from below 100 to 100.
     *
     * @return void - Asserts the rule penalises the default score but not the excluded-rule score.
     */
    public function testExcludeFromScoreRuleFindingsDoNotPenaliseTheComposite(): void
    {
        $registry = RuleRegistry::defaults();
        $findings = [
            $this->finding(FileLengthRule::ID, Pillar::Size, Severity::Error),
        ];

        $scoredWithRule    = (new ScoreCalculator())->calculate($findings, 10, null, DiffResult::inactive());
        $scoredWithoutRule = (new ScoreCalculator())->calculate($findings, 10, null, DiffResult::inactive(), analysisConfig: $this->configWithExcludedRule($registry, FileLengthRule::ID));

        self::assertLessThan(100.0, $this->composite($scoredWithRule)->score);
        self::assertSame(100.0, $this->composite($scoredWithoutRule)->score);
    }

    /**
     * Checks that a file-length finding still reduces the composite when analysisConfig is null.
     *
     * @return void - Asserts that the composite score is below 100 with a null analysis configuration.
     */
    public function testNullAnalysisConfigKeepsAllFindingsInScore(): void
    {
        $findings = [
            $this->finding(FileLengthRule::ID, Pillar::Size, Severity::Error),
        ];

        $score = (new ScoreCalculator())->calculate($findings, 10, null, DiffResult::inactive(), analysisConfig: null);

        self::assertLessThan(100.0, $this->composite($score)->score);
    }

    /**
     * Build an AnalysisConfig with one rule marked excludeFromScore.
     *
     * @param RuleRegistry $registry - Source of rule defaults the config is seeded from.
     * @param string $ruleId - Rule identifier present in the supplied registry.
     *
     * @return AnalysisConfig - Registry defaults with the named rule excluded from scoring and its other settings preserved.
     */
    private function configWithExcludedRule(RuleRegistry $registry, string $ruleId): AnalysisConfig
    {
        $config   = AnalysisConfig::fromRegistry($registry);
        $settings = $config->ruleSettings($ruleId);

        return $config->withRuleSettings($ruleId, new \GruffPhp\Engine\Config\RuleSettings(
            enabled:           $settings->enabled,
            thresholds:        $settings->thresholds,
            options:           $settings->options,
            severityThreshold: $settings->severityThreshold,
            excludeFromScore:  true,
        ));
    }

    /**
     * Key pillar scores by pillar name.
     *
     * @param list<\GruffPhp\Results\Scoring\PillarScore> $pillars - Pillar scores returned by the calculator fixture.
     *
     * @return array<string, \GruffPhp\Results\Scoring\PillarScore> - Last score per pillar name; empty input returns an empty array.
     */
    private function pillarMap(array $pillars): array
    {
        $pillarsByName = [];
        foreach ($pillars as $pillar) {
            $pillarsByName[$pillar->pillar] = $pillar;
        }

        return $pillarsByName;
    }

    /**
     * Build a finding fixture for assertions.
     *
     * @param string $ruleId - Rule identifier assigned to the fixture.
     * @param Pillar $pillar - Primary pillar assigned to the fixture.
     * @param Severity $severity - Severity assigned to the fixture.
     * @param string $filePath - File path assigned to the fixture.
     * @param int|null $line - Start line assigned to the fixture; null leaves it unspecified.
     * @param int|null $endLine - End line assigned to the fixture; null leaves it unspecified.
     * @param string|null $symbol - Symbol used for grouping and display; null supplies no symbol.
     * @param array<string, bool|float|int|string|null|array<array-key, bool|float|int|string|null>> $metadata - Metadata attached to the fixture.
     *
     * @return Finding - Fixture with High confidence, V01 tier, and the supplied scoring fields.
     */
    private function finding(
        string   $ruleId,
        Pillar   $pillar,
        Severity $severity,
        string   $filePath = 'src/Example.php',
        ?int     $line = 1,
        ?int     $endLine = null,
        ?string  $symbol = null,
        array    $metadata = [],
    ): Finding {
        return new Finding(
            ruleId:     $ruleId,
            message:    'Example finding.',
            filePath:   $filePath,
            line:       $line,
            severity:   $severity,
            pillar:     $pillar,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
            endLine:    $endLine,
            symbol:     $symbol,
            metadata:   $metadata,
        );
    }

    /**
     * Reads a fixture's composite after asserting its presence.
     * A run that evaluated nothing may omit it, but every caller here supplies a positive denominator.
     *
     * @param ScoreReport $score - Report whose composite the assertion is about to read.
     *
     * @return Grade - The composite grade, once its presence has been asserted.
     */
    private function composite(ScoreReport $score): Grade
    {
        self::assertNotNull($score->composite, 'the fixture evaluated files, so it must carry a composite');

        return $score->composite;
    }

}
