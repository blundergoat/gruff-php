<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CyclomaticComplexityRuleTest extends TestCase
{
    private CyclomaticComplexityRule $rule;
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->rule   = new CyclomaticComplexityRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function methodCcnProvider(): array
    {
        return [
            'flat method' => ['flat', 1],
            'if/elseif' => ['ifElseIf', 3],
            'loop with condition' => ['loopWithCondition', 4],
            'switch block' => ['switchBlock', 4],
            'match block' => ['matchBlock', 4],
            'mixed operators' => ['mixedOperators', 7],
            'try/catch loop' => ['tryCatchLoop', 4],
        ];
    }

    #[DataProvider('methodCcnProvider')]
    public function testCyclomaticCountMatchesExpected(string $methodName, int $expectedCcn): void
    {
        $unit    = $this->parseFixture('cyclomatic.php');
        $finder  = new NodeFinder();
        $methods = $finder->findInstanceOf($unit->statements, ClassMethod::class);

        $method = null;

        foreach ($methods as $m) {
            if ($m->name->toString() === $methodName) {
                $method = $m;

                break;
            }
        }

        self::assertNotNull($method, sprintf('Method %s not found in fixture.', $methodName));
        self::assertSame($expectedCcn, CyclomaticComplexityRule::computeCyclomaticComplexity($method));
    }

    public function testNoFindingsForSimpleMethods(): void
    {
        $findings = $this->analyse('simple.php', ['warning' => 10, 'error' => 20]);

        self::assertSame([], $findings);
    }

    public function testWarningForMethodAboveThreshold(): void
    {
        $findings = $this->analyse('cyclomatic.php', ['warning' => 3, 'error' => 20]);

        self::assertNotSame([], $findings);

        foreach ($findings as $finding) {
            self::assertSame(CyclomaticComplexityRule::ID, $finding->ruleId);
            self::assertArrayHasKey('complexity', $finding->metadata);
            self::assertGreaterThan(3, $finding->metadata['complexity']);
        }
    }

    public function testErrorForMethodAboveErrorThreshold(): void
    {
        $findings = $this->analyse('cyclomatic.php', ['warning' => 3, 'error' => 4]);

        $errors = array_values(array_filter($findings, static fn ($f) => $f->severity === Severity::Error));

        self::assertNotSame([], $errors);
    }

    /**
     * @param array<string, int> $thresholds
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            CyclomaticComplexityRule::ID,
            new RuleSettings(true, $thresholds),
        );

        return $this->rule->analyse($unit, new RuleContext(__DIR__ . '/../../..', $config));
    }

    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Complexity/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Complexity/' . $filename));
    }
}
