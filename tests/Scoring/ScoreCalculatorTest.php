<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Scoring;

use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Mutation\InfectionMutant;
use GruffPhp\Mutation\InfectionReport;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Scoring\CompositeFindingFactory;
use GruffPhp\Scoring\Grade;
use GruffPhp\Scoring\ScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Covers ScoreCalculatorTest behavior.
 */
final class ScoreCalculatorTest extends TestCase
{
    /**
     * Verify grade boundaries use simple af scale.
     *
     * @return void No return value.
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
     * @return void No return value.
     */
    public function testScoreReportOmitsMutationPillarWhenInfectionDataIsAbsent(): void
    {
        $score = (new ScoreCalculator())->calculate([
            $this->finding('docs.missing-public-phpdoc', Pillar::Documentation, Severity::Advisory),
            $this->finding('security.dangerous-function-call', Pillar::Security, Severity::Error),
        ], null, DiffResult::inactive());

        self::assertLessThan(100.0, $score->composite->score);
        self::assertSame('full-project', $score->scope);
        self::assertSame(
            'Per-pillar scores start at 100 and subtract weighted finding penalties; the composite is the average of applicable pillar scores. '
            . 'Mutation is omitted when no Infection report is supplied.',
            $score->explanation,
        );
        self::assertNotContains('mutation', array_map(static fn ($pillar): string => $pillar->pillar, $score->pillars));

        $security = array_values(array_filter($score->pillars, static fn ($pillar): bool => $pillar->pillar === 'security'))[0] ?? null;

        self::assertNotNull($security);
        self::assertSame(1, $security->errors);
        self::assertSame('F', $security->grade?->letter);
    }

    /**
     * Verify composite god method finding requires size and complexity on same symbol.
     *
     * @return void No return value.
     */
    public function testCompositeGodMethodFindingRequiresSizeAndComplexityOnSameSymbol(): void
    {
        $findings = [
            $this->finding('size.method-length', Pillar::Size, Severity::Warning, filePath: 'src/TooMuch.php', line: 12, endLine: 30, symbol: 'TooMuch::run()'),
            $this->finding('complexity.cognitive', Pillar::Complexity, Severity::Warning, filePath: 'src/TooMuch.php', line: 14, endLine: 28, symbol: 'TooMuch::run()'),
            $this->finding('complexity.cyclomatic', Pillar::Complexity, Severity::Warning, filePath: 'src/Other.php', line: 9, symbol: 'Other::run()'),
        ];

        $composites = (new CompositeFindingFactory())->build($findings);

        self::assertCount(1, $composites);
        self::assertSame('design.god-method', $composites[0]->ruleId);
        self::assertSame(Pillar::Design, $composites[0]->pillar);
        self::assertSame(12, $composites[0]->line);
        $expectedCompositeEndLine = 30;
        self::assertSame($expectedCompositeEndLine, $composites[0]->endLine);
        self::assertSame('TooMuch::run()', $composites[0]->symbol);
        self::assertSame(['complexity.cognitive', 'size.method-length'], $composites[0]->metadata['componentRules']);
        self::assertSame([Pillar::Complexity, Pillar::Size], $composites[0]->secondaryPillars);
    }

    /**
     * Verify score report includes mutation pillar, file mutation scores, distribution, and diff scope.
     *
     * @return void No return value.
     */
    public function testScoreReportIncludesMutationAndFileMetrics(): void
    {
        $findings = [
            $this->finding('complexity.cyclomatic', Pillar::Complexity, Severity::Warning, filePath: 'src/A.php', line: 4, metadata: ['complexity' => 4]),
            $this->finding('complexity.cyclomatic', Pillar::Complexity, Severity::Warning, filePath: 'src/A.php', line: 8, metadata: ['complexity' => 9]),
            $this->finding('complexity.cognitive', Pillar::Complexity, Severity::Warning, filePath: 'src/A.php', line: 12, metadata: ['complexity' => 12]),
            $this->finding('size.method-length', Pillar::Size, Severity::Advisory, filePath: 'src/A.php', line: 12, metadata: ['lines' => 44]),
            $this->finding('security.dangerous-function-call', Pillar::Security, Severity::Error, filePath: 'src/B.php', line: 2),
            $this->finding('mutation.survived', Pillar::Mutation, Severity::Warning, filePath: 'src/A.php', line: 20),
        ];
        $mutation = new MutationAnalysisResult(new InfectionReport(
            reportPath: 'infection-report.json',
            stats:      [
                'totalMutantsCount' => 3,
                'msi' => 66.67,
                'coveredCodeMsi' => 66.67,
                'mutationCodeCoverage' => 100.0,
            ],
            mutants: [
                new InfectionMutant('killed', 'src/A.php', 1, 'Plus'),
                new InfectionMutant('escaped', 'src/A.php', 2, 'Minus'),
                new InfectionMutant('killed', 'src/B.php', 3, 'ReturnRemoval'),
            ],
        ));

        $score = (new ScoreCalculator())->calculate($findings, $mutation, new DiffResult(
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
            'Per-pillar scores start at 100 and subtract weighted finding penalties; the composite is the average of applicable pillar scores. '
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
            'advisories' => 0,
            'warnings' => 1,
            'errors' => 0,
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
     * Build a finding fixture for assertions.
     *
     * @param string                                                                                 $ruleId   Rule identifier.
     * @param Pillar                                                                                 $pillar   Fixture value.
     * @param Severity                                                                               $severity Fixture value.
     * @param string                                                                                 $filePath Finding file path.
     * @param int                                                                                    $line     Finding line number.
     * @param string|null                                                                            $symbol   Fixture value.
     * @param array<string, bool|float|int|string|null|array<array-key, bool|float|int|string|null>> $metadata Fixture value.
     * @return Finding Fixture value.
     */
    private function finding(
        string $ruleId,
        Pillar $pillar,
        Severity $severity,
        string $filePath = 'src/Example.php',
        int $line = 1,
        ?int $endLine = null,
        ?string $symbol = null,
        array $metadata = [],
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
}
