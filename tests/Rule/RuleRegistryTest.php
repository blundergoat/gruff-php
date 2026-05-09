<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Complexity\CognitiveComplexityRule;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\Complexity\HalsteadVolumeRule;
use GruffPhp\Rule\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rule\Complexity\NestingDepthRule;
use GruffPhp\Rule\Complexity\NpathComplexityRule;
use GruffPhp\Rule\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rule\DeadCode\UnusedPrivatePropertyRule;
use GruffPhp\Rule\Waste\CommentedOutCodeRule;
use GruffPhp\Rule\Waste\EmptyClassRule;
use GruffPhp\Rule\Waste\EmptyMethodRule;
use GruffPhp\Rule\Waste\UnreachableCodeRule;
use GruffPhp\Rule\Waste\UnusedImportRule;
use GruffPhp\Rule\Waste\UnusedParameterRule;
use GruffPhp\Rule\Size\AverageMethodLengthRule;
use GruffPhp\Rule\Size\ClassLengthRule;
use GruffPhp\Rule\Size\FileLengthRule;
use GruffPhp\Rule\Size\MethodLengthRule;
use GruffPhp\Rule\Size\ParameterCountRule;
use GruffPhp\Rule\Size\PropertyCountRule;
use GruffPhp\Rule\Size\PublicMethodCountRule;
use GruffPhp\Source\SourceFile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RuleRegistryTest extends TestCase
{
    public function testDefaultRegistryContainsStableRuleIds(): void
    {
        $registry = RuleRegistry::defaults();

        self::assertTrue($registry->has(CognitiveComplexityRule::ID));
        self::assertTrue($registry->has(CyclomaticComplexityRule::ID));
        self::assertTrue($registry->has(HalsteadVolumeRule::ID));
        self::assertTrue($registry->has(MaintainabilityIndexRule::ID));
        self::assertTrue($registry->has(NestingDepthRule::ID));
        self::assertTrue($registry->has(NpathComplexityRule::ID));
        self::assertTrue($registry->has(UnusedPrivateMethodRule::ID));
        self::assertTrue($registry->has(UnusedPrivatePropertyRule::ID));
        self::assertTrue($registry->has(CommentedOutCodeRule::ID));
        self::assertTrue($registry->has(EmptyClassRule::ID));
        self::assertTrue($registry->has(EmptyMethodRule::ID));
        self::assertTrue($registry->has(UnreachableCodeRule::ID));
        self::assertTrue($registry->has(UnusedImportRule::ID));
        self::assertTrue($registry->has(UnusedParameterRule::ID));
        self::assertTrue($registry->has(AverageMethodLengthRule::ID));
        self::assertTrue($registry->has(ClassLengthRule::ID));
        self::assertTrue($registry->has(FileLengthRule::ID));
        self::assertTrue($registry->has(MethodLengthRule::ID));
        self::assertTrue($registry->has(ParameterCountRule::ID));
        self::assertTrue($registry->has(PropertyCountRule::ID));
        self::assertTrue($registry->has(PublicMethodCountRule::ID));
    }

    public function testRunsEnabledRulesOverParsedFiles(): void
    {
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            FileLengthRule::ID,
            new RuleSettings(true, ['warning' => 3, 'error' => 999]),
        );

        $allFindings = $registry->analyse(
            [$this->parseFixture('tests/Fixtures/M02/mixed/alpha.php')],
            new RuleContext(__DIR__ . '/../..', $config),
        );

        $findings = array_values(array_filter($allFindings, static fn ($f) => $f->ruleId === FileLengthRule::ID));

        self::assertCount(1, $findings);
        self::assertSame(FileLengthRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(Pillar::Size, $findings[0]->pillar);
        self::assertSame(['lines' => 14, 'threshold' => 3, 'thresholdType' => 'warning'], $findings[0]->metadata);
    }

    public function testSkipsDisabledRules(): void
    {
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            FileLengthRule::ID,
            new RuleSettings(false, ['warning' => 3, 'error' => 999]),
        );

        $allFindings = $registry->analyse(
            [$this->parseFixture('tests/Fixtures/M02/mixed/alpha.php')],
            new RuleContext(__DIR__ . '/../..', $config),
        );

        $fileLengthFindings = array_filter($allFindings, static fn ($f) => $f->ruleId === FileLengthRule::ID);
        self::assertSame([], array_values($fileLengthFindings));
    }

    public function testRejectsDuplicateRuleIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate rule id "test.duplicate".');

        new RuleRegistry([
            $this->fakeRule('test.duplicate'),
            $this->fakeRule('test.duplicate'),
        ]);
    }

    private function parseFixture(string $displayPath): AnalysisUnit
    {
        $absolutePath = __DIR__ . '/../..' . '/' . $displayPath;

        return (new PhpFileParser())->parse(new SourceFile($absolutePath, $displayPath));
    }

    private function fakeRule(string $id): RuleInterface
    {
        return new readonly class($id) implements RuleInterface {
            public function __construct(private string $id)
            {
            }

            public function definition(): RuleDefinition
            {
                return new RuleDefinition(
                    id: $this->id,
                    name: 'Fake rule',
                    pillar: Pillar::Maintainability,
                    tier: RuleTier::V01,
                    defaultSeverity: Severity::Advisory,
                    confidence: Confidence::Low,
                );
            }

            public function analyse(AnalysisUnit $unit, RuleContext $context): array
            {
                return [
                    new Finding(
                        ruleId: $this->id,
                        message: 'Fake finding.',
                        filePath: $unit->file->displayPath,
                        line: 1,
                        severity: Severity::Advisory,
                        pillar: Pillar::Maintainability,
                        tier: RuleTier::V01,
                        confidence: Confidence::Low,
                    ),
                ];
            }
        };
    }
}
