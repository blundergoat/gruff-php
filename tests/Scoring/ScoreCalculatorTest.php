<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Scoring;

use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Scoring\CompositeFindingFactory;
use GruffPhp\Scoring\Grade;
use GruffPhp\Scoring\ScoreCalculator;
use PHPUnit\Framework\TestCase;

final class ScoreCalculatorTest extends TestCase
{
    public function testGradeBoundariesUseSimpleAFScale(): void
    {
        self::assertSame('A', Grade::letterFor(90.0));
        self::assertSame('B', Grade::letterFor(80.0));
        self::assertSame('C', Grade::letterFor(70.0));
        self::assertSame('D', Grade::letterFor(60.0));
        self::assertSame('F', Grade::letterFor(59.99));
    }

    public function testScoreReportOmitsMutationPillarWhenInfectionDataIsAbsent(): void
    {
        $score = (new ScoreCalculator())->calculate([
            $this->finding('docs.missing-public-phpdoc', Pillar::Documentation, Severity::Advisory),
            $this->finding('security.dangerous-function-call', Pillar::Security, Severity::Error),
        ], null, DiffResult::inactive());

        self::assertLessThan(100.0, $score->composite->score);
        self::assertSame('full-project', $score->scope);
        self::assertNotContains('mutation', array_map(static fn ($pillar): string => $pillar->pillar, $score->pillars));

        $security = array_values(array_filter($score->pillars, static fn ($pillar): bool => $pillar->pillar === 'security'))[0] ?? null;

        self::assertNotNull($security);
        self::assertSame(1, $security->errors);
        self::assertSame('F', $security->grade?->letter);
    }

    public function testCompositeGodMethodFindingRequiresSizeAndComplexityOnSameSymbol(): void
    {
        $findings = [
            $this->finding('size.method-length', Pillar::Size, Severity::Warning, 'src/TooMuch.php', 12, 'TooMuch::run()'),
            $this->finding('complexity.cognitive', Pillar::Complexity, Severity::Warning, 'src/TooMuch.php', 12, 'TooMuch::run()'),
            $this->finding('complexity.cyclomatic', Pillar::Complexity, Severity::Warning, 'src/Other.php', 9, 'Other::run()'),
        ];

        $composites = (new CompositeFindingFactory())->build($findings);

        self::assertCount(1, $composites);
        self::assertSame('design.god-method', $composites[0]->ruleId);
        self::assertSame(Pillar::Design, $composites[0]->pillar);
        self::assertSame('TooMuch::run()', $composites[0]->symbol);
    }

    private function finding(
        string $ruleId,
        Pillar $pillar,
        Severity $severity,
        string $filePath = 'src/Example.php',
        int $line = 1,
        ?string $symbol = null,
    ): Finding {
        return new Finding(
            ruleId: $ruleId,
            message: 'Example finding.',
            filePath: $filePath,
            line: $line,
            severity: $severity,
            pillar: $pillar,
            tier: RuleTier::V01,
            confidence: Confidence::High,
            symbol: $symbol,
        );
    }
}
