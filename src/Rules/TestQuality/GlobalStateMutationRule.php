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
 * Flags indexed superglobal writes and listed state-function calls in PHPUnit tests without inferred cleanup.
 * Hooks are not checked for restoration, so findings identify possible leaks rather than proving them.
 * Pest scopes are skipped. Findings are warnings with medium confidence.
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
     * @return RuleDefinition - Rule metadata with warning severity and medium confidence defaults.
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A test that restores the mutated value inline at the end of its own body, in a class that declares no tearDown or #[After] hook.',
                    'mitigation' => 'Only a cleanup hook on the class or its visible parents clears the finding, so move the restore into tearDown or an #[After] method.',
                ],
            ],
        );
    }

    /**
     * Reports test scopes with tracked indexed superglobal assignments or function-call names when cleanup inference does not suppress scanning.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for tracked mutation syntax in scopes not suppressed by cleanup inference.
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
     * Decides whether a PHPUnit scope needs scanning after cleanup inference.
     *
     * @param TestQualityScope $scope - Scope under review; Pest closures are skipped.
     * @param array<int, bool> $cleanupCache - Memo by enclosing class object id; true exempts the class from scanning.
     * @param array<string, Stmt\Class_> $classesByName - Declared classes by name, used to resolve parent cleanup hooks.
     *
     * @return bool - True for a class-owned PHPUnit scope whose class is not exempted by cleanup inference.
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

        if (!array_key_exists($classKey, $cleanupCache)) {
            $cleanupCache[$classKey] = $this->hasCleanupInClass($class, $classesByName);
        }

        // A hook or an unavailable non-TestCase parent suppresses scanning without proving restoration.
        return !$cleanupCache[$classKey];
    }

    /**
     * Builds findings for simple indexed assignments to tracked superglobals in an eligible test scope.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit; supplies the display path recorded on each finding.
     * @param TestQualityScope $scope - Scope admitted by the cleanup inference check.
     *
     * @return list<Finding> - One finding per matching assignment; empty when none match.
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
     * Builds findings for function calls whose names match the tracked state-function list.
     *
     * @param AnalysisUnit     $analysisUnit - Parsed unit; supplies the display path recorded on each finding.
     * @param TestQualityScope $scope        - Test scope admitted by cleanup inference; its calls are matched against the state list.
     *
     * @return list<Finding> - One finding per matching function-call name in the scope; empty when none match.
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
     * Returns the tracked base name for an indexed assignment target, or null.
     *
     * @param Expr $target - Assignment target; nested array dimensions are unwrapped to the base variable.
     *
     * @return string|null - Tracked superglobal name, or null for bare, dynamic, or untracked targets.
     */
    private function superglobalWriteName(Expr $target): ?string
    {
        if (!$target instanceof Expr\ArrayDimFetch) {
            // Bare superglobal assignments are outside this rule's indexed-write check.
            return null;
        }

        $variable = $target->var;

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
     * Infers cleanup from a class, available ancestors, or an unavailable non-TestCase parent.
     *
     * @param Stmt\Class_ $class - Class whose methods and available ancestors are inspected.
     * @param array<string, Stmt\Class_> $classesByName - Declared classes by name, used to follow extends to a parent.
     * @param array<int, true> $visited - Object ids already walked; guards against cycles in the graph.
     *
     * @return bool - True for a recognized hook or an unavailable parent whose short name is not TestCase.
     */
    private function hasCleanupInClass(Stmt\Class_ $class, array $classesByName, array $visited = []): bool
    {
        $classId = spl_object_id($class);
        if (isset($visited[$classId])) {
            // Cycle break: a class already on the walk path contributes no new hook, so stop recursing.
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

            return false;
        }

        $parentName = $class->extends->toString();
        $parent     = $classesByName[$parentName] ?? $classesByName[$this->shortName($parentName)] ?? null;

        if (!$parent instanceof Stmt\Class_) {
            // Parent source is unavailable: assume cleanup exists unless it is the bare PHPUnit TestCase root.
            return !in_array($this->shortName($parentName), ['TestCase'], true);
        }


        return $this->hasCleanupInClass($parent, $classesByName, $visited);
    }

    /**
     * Indexes named classes by short name and available namespaced name.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose declared classes seed the lookup table.
     *
     * @return array<string, Stmt\Class_> - Named classes by short and available namespaced name; empty when none are named.
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
     * Returns the final segment of a fully qualified class name.
     *
     * @param string $name - Class name to split; a trailing separator yields an empty final segment.
     *
     * @return string - The final namespace segment, which may be empty.
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
     * @param array<string, scalar> $metadata - Structured detail (variant, name) used to group or filter.
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
