<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Stmt;

/**
 * Flags two test-naming smells in a PHPUnit class: a method whose name matches a low-signal pattern
 * (`testWorks`, `testFoo2`) and a class that mixes camelCase and snake_case test methods. A reviewer sees
 * names that read poorly in reports. Runs per class; the poor-name patterns are tunable. Advisory, high confidence.
 */
final readonly class TestNamingConsistencyRule implements RuleInterface
{
    /**
     * Stable identifier for the test naming consistency rule.
     */
    public const ID = 'test-quality.naming-consistency';

    /**
     * Default patterns that identify low-signal test names.
     */
    private const DEFAULT_POOR_NAME_PATTERNS = [
        '/^test[A-Z][A-Za-z]*(?:Works|Basic|Simple|Test)$/',
        '/^test[A-Z][A-Za-z]*\d+$/',
    ];

    /**
     * Describes the test-naming-consistency rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata, defaults, and options.
     */
    public function definition(): RuleDefinition
    {
        // Advisory by default: naming style is a convention, so the poor-name patterns ship as a tunable option.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Test naming consistency',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            defaultOptions:  ['poorNamePatterns' => self::DEFAULT_POOR_NAME_PATTERNS],
        );
    }

    /**
     * Reports mixed test naming styles and weakly descriptive test names.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for inconsistent or poor test names.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];
        $patterns = $ruleContext->settingsFor($this->definition())->stringListOption('poorNamePatterns');

        // Weigh every class declaration in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $camelCount = 0;
            $snakeCount = 0;
            $className  = $class->name?->toString() ?? sprintf('anonymous@%d', $class->getStartLine());

            // Inspect each method for test-name style and quality.
            foreach ($class->getMethods() as $method) {
                // Only real test methods are named against the conventions.
                if (!TestQualityNodeHelper::isTestMethod($method)) {
                    continue;
                }

                $methodName = $method->name->toString();
                $afterTest  = substr($methodName, 4);

                // Classify the naming style only when the name has text after test.
                if ($afterTest !== '') {
                    // An underscore marks snake_case; anything else is camelCase.
                    if (str_contains($afterTest, '_')) {
                        $snakeCount++;
                    } else {
                        $camelCount++;
                    }
                }

                $matchedPattern = $this->matchPoorNamePattern($methodName, $patterns);
                // A name matching a poor-name pattern earns its own finding.
                if ($matchedPattern !== null) {
                    $findings[] = new Finding(
                        ruleId:      self::ID,
                        message:     sprintf('%s::%s() has a poorly descriptive test name (matches %s).', $className, $methodName, $matchedPattern),
                        filePath:    $analysisUnit->file->displayPath,
                        line:        $method->getStartLine(),
                        severity:    Severity::Advisory,
                        pillar:      Pillar::TestQuality,
                        tier:        RuleTier::V01,
                        confidence:  Confidence::High,
                        symbol:      sprintf('%s::%s()', $className, $methodName),
                        remediation: 'Rename the test to describe the scenario and expected behaviour rather than a generic suffix or numeric counter.',
                        metadata:    ['variant' => 'poor-name', 'pattern' => $matchedPattern],
                    );
                }
            }

            // A single, consistent naming style across the class is fine.
            if ($camelCount === 0 || $snakeCount === 0) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s mixes camelCase (%d) and snake_case (%d) test method naming.', $className, $camelCount, $snakeCount),
                filePath:    $analysisUnit->file->displayPath,
                line:        $class->getStartLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                symbol:      $className,
                remediation: 'Pick one naming style for test methods and apply it consistently.',
                metadata:    ['variant' => 'mixed-style', 'camelCase' => $camelCount, 'snake_case' => $snakeCount],
            );
        }

        return $findings;
    }

    /**
     * Returns the first configured poor-name pattern the method name matches, or null.
     *
     * @param string       $methodName - Full test method name including the `test` prefix, matched as-is.
     * @param list<string> $patterns - Configured regexes flagging low-signal names; first match wins.
     *
     * @return string|null - Matching poor-name pattern, or null when none match.
     */
    private function matchPoorNamePattern(string $methodName, array $patterns): ?string
    {
        // Try each configured poor-name pattern in order.
        foreach ($patterns as $pattern) {
            if ($this->isPatternMatch($pattern, $methodName)) {
                // Surface the offending regex so the finding can name which rule the test name tripped.
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Reports whether a user-configured regex safely matches a method name.
     *
     * @param string $pattern - User-configured regex with delimiters; an invalid pattern matches nothing, not errors.
     * @param string $methodName - Test method name to test the pattern against.
     *
     * @return bool - True when the pattern matches.
     */
    private function isPatternMatch(string $pattern, string $methodName): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            // Apply the configured test-name regex while suppressing invalid-pattern warnings.
            return preg_match($pattern, $methodName) === 1;
        } finally {
            restore_error_handler();
        }
    }

}
