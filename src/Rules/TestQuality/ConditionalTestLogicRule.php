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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Flags test-owned `if` statements that can choose which scenario or assertions run.
 * Fixture callbacks and terminating PHPUnit skip guards stay quiet; matrix-style suites can exempt
 * paths via `ignoredPathPatterns`. Runs over every test. Advisory, high confidence.
 */
final readonly class ConditionalTestLogicRule implements RuleInterface
{
    /**
     * Stable rule identifier for conditional test logic findings.
     */
    public const ID = 'test-quality.conditional-logic';

    /**
     * Describes the conditional-test-logic rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory: linear tests are a strong default, but matrix-style suites legitimately branch, so teams opt in.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Conditional test logic',
            pillar:             Pillar::TestQuality,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::High,
            isEnabledByDefault: false,
            defaultOptions:     ['ignoredPathPatterns' => []],
        );
    }

    /**
     * Reports test cases that hide behaviour behind conditionals.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for conditional logic inside tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        if ($this->isPathIgnored($analysisUnit->file->displayPath, $settings->stringListOption('ignoredPathPatterns'))) {
            // Project opted this path out of the rule, so emit nothing rather than reporting expected branching.
            return [];
        }

        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // Fixture callbacks own their branches; terminating skip guards select supported environments.
            foreach (NodeIndex::descendantsOfAny($scope->node, [Stmt\If_::class]) as $conditional) {
                if (!$this->isTestOwned($conditional, $scope->node) || $this->isSkipGuard($conditional)) {
                    continue;
                }
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
                    remediation: 'Move the branching out of the test body: give each case its own #[DataProvider] row with its input and expected value, so every row runs the same straight-line assertions.',
                );
            }
        }

        return $findings;
    }

    /**
     * Keeps fixture callback control flow outside the enclosing test's branch policy.
     *
     * @param Node $node - Conditional found below the test scope.
     * @param Node $test - Method or Pest callback owning the test.
     * @return bool - True when no nested callable separates the conditional from the test.
     */
    private function isTestOwned(Node $node, Node $test): bool
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node && $parent !== $test; $parent = $parent->getAttribute('parent')) {
            if ($parent instanceof Node\FunctionLike) {
                return false;
            }
        }

        return true;
    }

    /**
     * Accepts a branch consisting only of PHPUnit's terminating skip and an optional return.
     *
     * @param Stmt\If_ $conditional - Test-owned branch; else arms or additional work remain policy.
     * @return bool - True when the branch can only skip this unsupported scenario.
     */
    private function isSkipGuard(Stmt\If_ $conditional): bool
    {
        if ($conditional->else !== null || $conditional->elseifs !== []) {
            return false;
        }

        $hasSkip = false;
        foreach ($conditional->stmts as $statement) {
            if ($hasSkip && $statement instanceof Stmt\Return_ && $statement->expr === null) {
                continue;
            }
            if (!$statement instanceof Stmt\Expression || !$this->isPhpUnitSkip($statement->expr)) {
                return false;
            }
            $hasSkip = true;
        }

        return $hasSkip;
    }

    /**
     * Binds the skip to PHPUnit's current instance or class rather than a foreign receiver.
     *
     * @param Expr $expression - Sole executable action in a possible skip guard.
     * @return bool - True for a direct markTestSkipped call with a supplied reason.
     */
    private function isPhpUnitSkip(Expr $expression): bool
    {
        if ((!$expression instanceof Expr\MethodCall && !$expression instanceof Expr\StaticCall)
            || TestQualityNodeHelper::callName($expression) !== 'marktestskipped' || $expression->args === []) {
            return false;
        }

        return $expression instanceof Expr\MethodCall
            ? $expression->var instanceof Expr\Variable && $expression->var->name === 'this'
            : $expression->class instanceof Node\Name && in_array(strtolower($expression->class->toString()), ['self', 'static'], true);
    }

    /**
     * Reports whether a project-configured path exemption applies.
     *
     * @param string       $displayPath - Repository-relative path of the analysed file, used as the fnmatch subject.
     * @param list<string> $patterns    - Glob patterns the caller configured to exempt known matrix-style test paths.
     *
     * @return bool - True when the display path matches an ignored pattern.
     */
    private function isPathIgnored(string $displayPath, array $patterns): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        // Weigh the display path against each configured exemption glob.
        foreach ($patterns as $pattern) {
            // A matching pattern opts this path out of the rule.
            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }
}
