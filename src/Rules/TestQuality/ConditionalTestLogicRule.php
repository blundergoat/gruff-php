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
 * Detects branches in tests that make outcomes depend on local control flow.
 */
final readonly class ConditionalTestLogicRule implements RuleInterface
{
    /**
     * Stable rule identifier for conditional test logic findings.
     */
    public const ID = 'test-quality.conditional-logic';

    /**
     * Describe the conditional test logic rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory: linear tests are a strong default, but matrix-style suites legitimately branch, so teams opt in.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Conditional test logic',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            defaultOptions:  ['ignoredPathPatterns' => []],
        );
    }

    /**
     * Find test cases that hide behavior behind conditionals.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for conditional logic inside tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        // User view: choose the findings list branch for this case.
        if ($this->isPathIgnored($analysisUnit->file->displayPath, $settings->stringListOption('ignoredPathPatterns'))) {
            // Project opted this path out of the rule, so emit nothing rather than reporting expected branching.
            return [];
        }

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // User view: add each item that can appear in findings list.
            foreach (NodeIndex::descendantsOfAny($scope->node, [Stmt\If_::class]) as $conditional) {
                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s contains conditional logic; tests should usually be linear.', $scope->symbol),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $conditional->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::High,
                    symbol:      $scope->symbol,
                    remediation: 'Split branches into separate test cases with explicit setup and expectations. If a path exercises framework-driven matrix tests where conditional branching is unavoidable, add it to `rules.test-quality.conditional-logic.options.ignoredPathPatterns` in `.gruff-php.yaml`.',
                );
            }
        }

        return $findings;
    }

    /**
     * Check whether a project-configured path exemption applies.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string       $displayPath - Repository-relative path of the analysed file, used as the fnmatch subject.
     * @param list<string> $patterns - Glob patterns the caller configured to exempt known matrix-style test paths.
     *
     * @return bool - True when the display path matches an ignored pattern.
     */
    private function isPathIgnored(string $displayPath, array $patterns): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        // User view: add each item that can appear in findings list.
        foreach ($patterns as $pattern) {
            // User view: choose the findings list branch for this case.
            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }
}
