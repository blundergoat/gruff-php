<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Detects tests that mutate shared process state without cleanup.
 */
final readonly class GlobalStateMutationRule implements RuleInterface
{
    /**
     * Stable identifier for the global-state mutation rule.
     */
    public const ID = 'test-quality.global-state-mutation';

    /**
     * Superglobal names that represent mutable process-wide state.
     */
    private const SUPERGLOBALS = ['_GET', '_POST', '_REQUEST', '_SERVER', '_ENV', '_COOKIE', '_FILES', 'GLOBALS'];

    /**
     * Functions that mutate PHP process or environment state.
     */
    private const STATE_FUNCTIONS = ['putenv', 'ini_set', 'error_reporting', 'date_default_timezone_set'];

    /**
     * Describe the global state mutation rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Global state mutation in test',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find tests that mutate global state without detected cleanup hooks.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for unscoped global state mutation.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings      = [];
        $cleanupCache  = [];
        $classesByName = $this->classesByName($analysisUnit);

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            if (!$this->shouldCheckScopeCleanup($scope, $cleanupCache, $classesByName)) {
                continue;
            }

            $findings = array_merge(
                $findings,
                $this->superglobalFindings($analysisUnit, $scope),
                $this->stateFunctionFindings($analysisUnit, $scope),
            );
        }

        return $findings;
    }

    /**
     * @param array<int, bool>           $cleanupCache
     * @param array<string, Stmt\Class_> $classesByName
     *
     * @return bool True when the scope should be scanned for cleanup-sensitive mutations.
     */
    private function shouldCheckScopeCleanup(TestQualityScope $scope, array &$cleanupCache, array $classesByName): bool
    {
        if ($scope->isPest) {
            return false;
        }

        $class = $scope->node->getAttribute('parent');
        if (!$class instanceof Stmt\Class_) {
            return false;
        }

        $classKey = spl_object_id($class);
        if (!array_key_exists($classKey, $cleanupCache)) {
            $cleanupCache[$classKey] = $this->hasCleanupInClass($class, $classesByName);
        }

        return !$cleanupCache[$classKey];
    }

    /**
     * Build superglobal findings for the test-quality rule.
     *
     * @return list<Finding>
     */
    private function superglobalFindings(AnalysisUnit $analysisUnit, TestQualityScope $scope): array
    {
        $findings = [];

        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\Assign::class]) as $assign) {
            $superglobal = $this->superglobalWriteName($assign->var);
            if ($superglobal === null) {
                continue;
            }

            $findings[] = $this->finding(
                analysisUnit: $analysisUnit,
                scope:        $scope,
                line:         $assign->getStartLine(),
                message:      sprintf('%s writes to $%s without a tearDown / #[After] cleanup.', $scope->symbol, $superglobal),
                metadata:     ['variant' => 'superglobal', 'name' => $superglobal],
            );
        }

        return $findings;
    }

    /**
     * Build state function findings for the test-quality rule.
     *
     * @return list<Finding>
     */
    private function stateFunctionFindings(AnalysisUnit $analysisUnit, TestQualityScope $scope): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if (!$call instanceof Expr\FuncCall) {
                continue;
            }

            $name = TestQualityNodeHelper::functionName($call);
            if ($name === null || !in_array($name, self::STATE_FUNCTIONS, true)) {
                continue;
            }

            $findings[] = $this->finding(
                analysisUnit: $analysisUnit,
                scope:        $scope,
                line:         $call->getStartLine(),
                message:      sprintf('%s calls %s() without a tearDown / #[After] cleanup.', $scope->symbol, $name),
                metadata:     ['variant' => 'function', 'name' => $name],
            );
        }

        return $findings;
    }

    /**
     * Extract the written superglobal name from an assignment target.
     *
     * @return string|null Superglobal name, or null when the assignment is not to a tracked superglobal.
     */
    private function superglobalWriteName(Expr $target): ?string
    {
        if (!$target instanceof Expr\ArrayDimFetch) {
            return null;
        }

        $variable = $target->var;
        while ($variable instanceof Expr\ArrayDimFetch) {
            $variable = $variable->var;
        }

        if (!$variable instanceof Expr\Variable || !is_string($variable->name)) {
            return null;
        }

        return in_array($variable->name, self::SUPERGLOBALS, true) ? $variable->name : null;
    }

    /**
     * @param array<string, Stmt\Class_> $classesByName
     * @param array<int, true>           $visited
     *
     * @return bool True when the class or an ancestor declares a cleanup hook.
     */
    private function hasCleanupInClass(Stmt\Class_ $class, array $classesByName, array $visited = []): bool
    {
        $classId = spl_object_id($class);
        if (isset($visited[$classId])) {
            return false;
        }

        $visited[$classId] = true;

        foreach ($class->getMethods() as $classMethod) {
            $methodName = strtolower($classMethod->name->toString());

            if (in_array($methodName, ['teardown', 'teardownafterclass'], true)) {
                return true;
            }

            $doc = strtolower($classMethod->getDocComment()?->getText() ?? '');
            if (str_contains($doc, '@after') || str_contains($doc, '@afterclass')) {
                return true;
            }

            if (TestQualityNodeHelper::hasAttribute($classMethod, 'After')
                || TestQualityNodeHelper::hasAttribute($classMethod, 'AfterClass')
            ) {
                return true;
            }
        }

        if ($class->extends === null) {
            return false;
        }

        $parentName = $class->extends->toString();
        $parent     = $classesByName[$parentName] ?? $classesByName[$this->shortName($parentName)] ?? null;

        if (!$parent instanceof Stmt\Class_) {
            return !in_array($this->shortName($parentName), ['TestCase'], true);
        }

        return $this->hasCleanupInClass($parent, $classesByName, $visited);
    }

    /**
     * Index declared classes by fully qualified and short names.
     *
     * @return array<string, Stmt\Class_>
     */
    private function classesByName(AnalysisUnit $analysisUnit): array
    {
        $classes = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            $name           = $class->name->toString();
            $classes[$name] = $class;
            $namespacedName = $class->getAttribute('namespacedName');

            if ($namespacedName instanceof Node\Name) {
                $classes[$namespacedName->toString()] = $class;
            }
        }

        return $classes;
    }

    /**
     * Return the final segment of a fully qualified class name.
     *
     * @return string Unqualified class name.
     */
    private function shortName(string $name): string
    {
        $parts = explode('\\', $name);

        return $parts[array_key_last($parts)] ?? $name;
    }

    /**
     * @param array<string, scalar> $metadata
     *
     * @return Finding Global state mutation finding.
     */
    private function finding(
        AnalysisUnit $analysisUnit,
        TestQualityScope $scope,
        int $line,
        string $message,
        array $metadata,
    ): Finding {
        return new Finding(
            ruleId:      self::ID,
            message:     $message,
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    Severity::Warning,
            pillar:      Pillar::TestQuality,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            symbol:      $scope->symbol,
            remediation: 'Reset the value in tearDown / #[After] or scope the change to a fixture-managed environment so the next test starts clean.',
            metadata:    $metadata,
        );
    }
}
