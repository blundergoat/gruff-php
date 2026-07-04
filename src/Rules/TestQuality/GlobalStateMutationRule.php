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
 * Flags a test that mutates process-wide state - writing a superglobal like `$_GET[...]` or calling
 * `putenv`/`ini_set` - in a class with no tearDown / #[After] cleanup, so the change leaks into whichever
 * test runs next and makes failures order-dependent. Runs over every test. Warning, medium confidence.
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
     * Describes the global-state-mutation rule for the registry and reports.
     *
     * @return RuleDefinition - id, name, pillar, tier, and the warning/medium defaults applied to every finding
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
     * Reports tests that mutate global state without a detected cleanup hook.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unscoped global state mutation.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings      = [];
        $cleanupCache  = [];
        $classesByName = $this->classesByName($analysisUnit);

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // Only scopes in a cleanup-free class can leak state into the next test.
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
     * Reports whether a scope should be scanned for cleanup-sensitive mutations.
     *
     * @param TestQualityScope           $scope - Scope to test; Pest scopes are exempt from cleanup checks.
     * @param array<int, bool>           $cleanupCache - Memo by enclosing class object id; true means a hook was found.
     * @param array<string, Stmt\Class_> $classesByName - Declared classes by name, used to resolve parent cleanup hooks.
     *
     * @return bool - True when the scope should be scanned for cleanup-sensitive mutations.
     */
    private function shouldCheckScopeCleanup(TestQualityScope $scope, array &$cleanupCache, array $classesByName): bool
    {
        if ($scope->isPest) {
            // Pest closures carry no class to hold a cleanup hook, so this rule has nothing to assert against them.
            return false;
        }

        $class = $scope->node->getAttribute('parent');
        if (!$class instanceof Stmt\Class_) {
            // A method outside a class cannot inherit tearDown either; skip rather than guess.
            return false;
        }

        $classKey = spl_object_id($class);
        // Resolve the class's cleanup verdict once and memoise it.
        if (!array_key_exists($classKey, $cleanupCache)) {
            $cleanupCache[$classKey] = $this->hasCleanupInClass($class, $classesByName);
        }

        // Scan only when the class has no cleanup hook; a present hook is assumed to reset the mutation.
        return !$cleanupCache[$classKey];
    }

    /**
     * Builds the findings for unscoped superglobal writes in a test.
     *
     * @param AnalysisUnit     $analysisUnit - Parsed unit; supplies the display path recorded on each finding.
     * @param TestQualityScope $scope - Cleanup-free test scope scanned for direct superglobal writes.
     *
     * @return list<Finding> - one finding per unscoped superglobal write in the scope; empty when none are written
     */
    private function superglobalFindings(AnalysisUnit $analysisUnit, TestQualityScope $scope): array
    {
        $findings = [];

        // Weigh every assignment in the test body.
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\Assign::class]) as $assign) {
            $superglobal = $this->superglobalWriteName($assign->var);
            // Skip assignments that do not write a tracked superglobal.
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
     * Builds the findings for unscoped state-mutating calls in a test.
     *
     * @param AnalysisUnit     $analysisUnit - Parsed unit; supplies the display path recorded on each finding.
     * @param TestQualityScope $scope - Cleanup-free test scope; its calls are matched against the state list.
     *
     * @return list<Finding> - one finding per unscoped state-mutating call in the scope; empty when none are called
     */
    private function stateFunctionFindings(AnalysisUnit $analysisUnit, TestQualityScope $scope): array
    {
        $findings = [];

        // Inspect each call the test makes.
        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            // Only a global function call can be a state mutator.
            if (!$call instanceof Expr\FuncCall) {
                continue;
            }

            $name = TestQualityNodeHelper::functionName($call);
            // Only the modelled state-mutating functions qualify.
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
     * Returns the superglobal name an assignment target writes to, or null.
     *
     * @param Expr $target - Left-hand side of an assignment; nested array-dim writes are unwrapped to the base variable.
     *
     * @return string|null - Superglobal name, or null when the assignment is not to a tracked superglobal.
     */
    private function superglobalWriteName(Expr $target): ?string
    {
        if (!$target instanceof Expr\ArrayDimFetch) {
            // Only indexed writes such as $_GET['x'] mutate a superglobal; a bare assignment is out of scope.
            return null;
        }

        $variable = $target->var;
        // Unwrap nested index writes down to the base variable.
        while ($variable instanceof Expr\ArrayDimFetch) {
            $variable = $variable->var;
        }

        if (!$variable instanceof Expr\Variable || !is_string($variable->name)) {
            // A dynamic or non-variable base ($$x[...]) cannot be resolved to a known superglobal name.
            return null;
        }

        return in_array($variable->name, self::SUPERGLOBALS, true) ? $variable->name : null;
    }

    /**
     * Reports whether a class or an ancestor declares a cleanup hook.
     *
     * @param Stmt\Class_                $class - Class whose own methods and ancestors are searched for a hook.
     * @param array<string, Stmt\Class_> $classesByName - Declared classes by name, used to follow `extends` to a parent.
     * @param array<int, true>           $visited - Object ids already walked; guards against cycles in the graph.
     *
     * @return bool - True when the class or an ancestor declares a cleanup hook.
     */
    private function hasCleanupInClass(Stmt\Class_ $class, array $classesByName, array $visited = []): bool
    {
        $classId = spl_object_id($class);
        if (isset($visited[$classId])) {
            // Cycle break: a class already on the walk path contributes no new hook, so stop recursing.
            return false;
        }

        $visited[$classId] = true;

        // Weigh each method for a cleanup hook.
        foreach ($class->getMethods() as $classMethod) {
            $methodName = strtolower($classMethod->name->toString());

            if (in_array($methodName, ['teardown', 'teardownafterclass'], true)) {
                // A tearDown / tearDownAfterClass method is treated as cleanup, so the class is exempt.
                return true;
            }

            $doc = strtolower($classMethod->getDocComment()?->getText() ?? '');
            if (str_contains($doc, '@after') || str_contains($doc, '@afterclass')) {
                // An @after / @afterClass annotation marks an arbitrarily named cleanup hook; honour it.
                return true;
            }

            if (TestQualityNodeHelper::hasAttribute($classMethod, 'After')
                || TestQualityNodeHelper::hasAttribute($classMethod, 'AfterClass')
            ) {
                // The #[After] / #[AfterClass] attribute is the modern cleanup form; treat it the same.
                return true;
            }
        }

        if ($class->extends === null) {
            // No own hook and no parent to inherit one from, so this class has no cleanup.
            return false;
        }

        $parentName = $class->extends->toString();
        $parent     = $classesByName[$parentName] ?? $classesByName[$this->shortName($parentName)] ?? null;

        if (!$parent instanceof Stmt\Class_) {
            // Parent source is unavailable: assume cleanup exists unless it is the bare PHPUnit TestCase root.
            return !in_array($this->shortName($parentName), ['TestCase'], true);
        }

        // Inherit the parent's verdict; a hook anywhere up the chain clears the whole subtree.
        return $this->hasCleanupInClass($parent, $classesByName, $visited);
    }

    /**
     * Indexes declared classes by short and namespaced name.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose declared classes seed the lookup table.
     *
     * @return array<string, Stmt\Class_> - declared classes keyed by both short and namespaced name; empty when the unit declares none
     */
    private function classesByName(AnalysisUnit $analysisUnit): array
    {
        $classes = [];

        // Weigh every class declaration in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            // Only a named class can be keyed for lookup.
            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            $name           = $class->name->toString();
            $classes[$name] = $class;
            $namespacedName = $class->getAttribute('namespacedName');

            // Also key the class by its namespaced name when known.
            if ($namespacedName instanceof Node\Name) {
                $classes[$namespacedName->toString()] = $class;
            }
        }

        return $classes;
    }

    /**
     * Returns the final segment of a fully qualified class name.
     *
     * @param string $name - Fully qualified or already-short class name; an empty-segment result falls back to the input.
     *
     * @return string - the trailing namespace segment, used to match `extends` targets against short class names
     */
    private function shortName(string $name): string
    {
        $parts = explode('\\', $name);

        return $parts[array_key_last($parts)] ?? $name;
    }

    /**
     * Builds the global-state-mutation finding.
     *
     * @param AnalysisUnit          $analysisUnit - Parsed unit; supplies the display path recorded on the finding.
     * @param TestQualityScope      $scope - Offending test scope; its symbol identifies the test in the finding.
     * @param int                   $line - 1-based source line of the mutation, reported to the user.
     * @param string                $message - Human-readable text naming the mutated state and the missing cleanup.
     * @param array<string, scalar> $metadata - Structured detail (`variant`, `name`) used to group or filter.
     *
     * @return Finding - the mutation report stamped with this rule's fixed pillar, tier, severity, and remediation text
     */
    private function finding(
        AnalysisUnit     $analysisUnit,
        TestQualityScope $scope,
        int              $line,
        string           $message,
        array            $metadata,
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
