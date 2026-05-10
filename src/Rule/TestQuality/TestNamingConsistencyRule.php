<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final readonly class TestNamingConsistencyRule implements RuleInterface
{
    public const ID = 'test-quality.naming-consistency';

    private const DEFAULT_POOR_NAME_PATTERNS = [
        '/^test[A-Z][A-Za-z]*(?:Works|Basic|Simple|Ok|Test)$/',
        '/^test[A-Z][A-Za-z]*\d+$/',
    ];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Test naming consistency',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::High,
            defaultOptions: ['poorNamePatterns' => self::DEFAULT_POOR_NAME_PATTERNS],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];
        $patterns = $context->settingsFor($this->definition())->stringListOption('poorNamePatterns');

        foreach ($finder->findInstanceOf($unit->statements, Stmt\Class_::class) as $class) {
            $camelCount = 0;
            $snakeCount = 0;

            foreach ($class->getMethods() as $method) {
                if (!TestQualityNodeHelper::isTestMethod($method)) {
                    continue;
                }

                $methodName = $method->name->toString();
                $afterTest = substr($methodName, 4);

                if ($afterTest !== '') {
                    if (str_contains($afterTest, '_')) {
                        $snakeCount++;
                    } else {
                        $camelCount++;
                    }
                }

                $matchedPattern = $this->matchPoorNamePattern($methodName, $patterns);
                if ($matchedPattern !== null) {
                    $findings[] = new Finding(
                        ruleId: self::ID,
                        message: sprintf('%s::%s() has a poorly descriptive test name (matches %s).', $this->className($class), $methodName, $matchedPattern),
                        filePath: $unit->file->displayPath,
                        line: $method->getStartLine(),
                        severity: Severity::Advisory,
                        pillar: Pillar::TestQuality,
                        tier: RuleTier::V01,
                        confidence: Confidence::High,
                        symbol: sprintf('%s::%s()', $this->className($class), $methodName),
                        remediation: 'Rename the test to describe the scenario and expected behaviour rather than a generic suffix or numeric counter.',
                        metadata: ['variant' => 'poor-name', 'pattern' => $matchedPattern],
                    );
                }
            }

            if ($camelCount === 0 || $snakeCount === 0) {
                continue;
            }

            $className = $this->className($class);
            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('%s mixes camelCase (%d) and snake_case (%d) test method naming.', $className, $camelCount, $snakeCount),
                filePath: $unit->file->displayPath,
                line: $class->getStartLine(),
                severity: Severity::Advisory,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                symbol: $className,
                remediation: 'Pick one naming style for test methods and apply it consistently.',
                metadata: ['variant' => 'mixed-style', 'camelCase' => $camelCount, 'snake_case' => $snakeCount],
            );
        }

        return $findings;
    }

    /**
     * @param list<string> $patterns
     */
    private function matchPoorNamePattern(string $methodName, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if ($this->patternMatches($pattern, $methodName)) {
                return $pattern;
            }
        }

        return null;
    }

    private function patternMatches(string $pattern, string $methodName): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, $methodName) === 1;
        } finally {
            restore_error_handler();
        }
    }

    private function className(Stmt\Class_ $class): string
    {
        return $class->name?->toString() ?? sprintf('anonymous@%d', $class->getStartLine());
    }
}
