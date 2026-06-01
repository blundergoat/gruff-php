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
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Detects repeated test bodies that should likely use a data provider.
 */
final readonly class RepeatedStructureMissingDataProviderRule implements RuleInterface
{
    /**
     * Stable identifier for the repeated-structure rule.
     */
    public const ID = 'test-quality.repeated-structure-missing-data-provider';

    /**
     * Minimum identical-shape group size before recommending a data provider.
     */
    private const MIN_GROUP_SIZE = 3;

    /**
     * Describe the repeated test structure rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory at low confidence: structural sameness is a weak signal that a data provider fits, never proof.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Repeated test structure missing data provider',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Low,
            defaultOptions:  ['ignoredPathPatterns' => []],
        );
    }

    /**
     * Find repeated test bodies that look like data-provider candidates.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for repeated test structures.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        if ($this->isPathIgnored($analysisUnit->file->displayPath, $settings->stringListOption('ignoredPathPatterns'))) {
            // Paths the project has opted out report nothing; their repetition is accepted by configuration.
            return [];
        }

        $nodeFinder = new NodeFinder();
        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $className = $class->name?->toString();
            if ($className === null) {
                continue;
            }

            $groups = [];

            foreach ($class->getMethods() as $classMethod) {
                if (!TestQualityNodeHelper::isTestMethod($classMethod)) {
                    continue;
                }

                if ($this->usesDataProvider($classMethod)) {
                    continue;
                }

                $stmts = array_values($classMethod->stmts ?? []);
                if (count($stmts) < 2) {
                    continue;
                }

                $shape = $this->fingerprint($stmts, $nodeFinder);
                $groups[$shape] ??= [];
                $groups[$shape][] = $classMethod;
            }

            foreach ($groups as $methods) {
                if (count($methods) < self::MIN_GROUP_SIZE) {
                    continue;
                }

                $names = array_map(static fn (Stmt\ClassMethod $classMethod): string => $classMethod->name->toString(), $methods);
                $first = $methods[0];

                $findings[] = new Finding(
                    ruleId:  self::ID,
                    message: sprintf(
                        '%s has %d structurally identical test methods (%s) that look like a data provider would replace.',
                        $className,
                        count($methods),
                        implode(', ', $names),
                    ),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $first->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Low,
                    symbol:      sprintf('%s::%s()', $className, $first->name->toString()),
                    remediation: 'Collapse the repeated tests into one method driven by #[DataProvider] or @dataProvider with the differing values as data rows. If a path consistently produces structurally similar tests by design, add it to `rules.test-quality.repeated-structure-missing-data-provider.options.ignoredPathPatterns` in `.gruff-php.yaml`.',
                    metadata:    ['count' => count($methods), 'methods' => $names],
                );
            }
        }

        // Hand back one finding per group of same-shaped tests that reached the minimum group size across all classes.
        return $findings;
    }

    /**
     * Check whether a project-configured path exemption applies.
     *
     * @param string       $displayPath File path under analysis; matched after backslashes are normalised to slashes.
     * @param list<string> $patterns    Glob patterns for accepted test shapes.
     * @return bool True when the display path matches an ignored pattern.
     */
    private function isPathIgnored(string $displayPath, array $patterns): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                // First matching glob is enough to exempt the path; no need to test the rest.
                return true;
            }
        }

        // No configured glob matched, so this path stays in scope for the rule.
        return false;
    }

    /**
     * Check whether a test method already declares a data provider.
     *
     * @param Stmt\ClassMethod $classMethod Test method whose attributes and docblock are scanned for a provider.
     * @return bool True when an attribute or docblock data provider is present.
     */
    private function usesDataProvider(Stmt\ClassMethod $classMethod): bool
    {
        foreach ($classMethod->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (strtolower($attr->name->getLast()) === 'dataprovider') {
                    // A #[DataProvider] attribute already drives this method, so it is exempt from the heuristic.
                    return true;
                }
            }
        }

        // No attribute matched; fall back to the legacy @dataProvider docblock tag as the remaining signal.
        return str_contains($classMethod->getDocComment()?->getText() ?? '', '@dataProvider');
    }

    /**
     * Reduce a test body to a token string capturing only its call and control-flow shape, ignoring literal values.
     *
     * @param list<Node> $stmts      Statements of one test method body; the unit being fingerprinted.
     * @param NodeFinder $nodeFinder Finder shared across the class scan so one instance serves every method.
     *
     * @return string Structure fingerprint for comparison across tests.
     */
    private function fingerprint(array $stmts, NodeFinder $nodeFinder): string
    {
        $tokens = [];

        foreach ($nodeFinder->find($stmts, static fn (Node $node): bool => $node instanceof Expr\New_
            || $node instanceof Expr\FuncCall
            || $node instanceof Expr\MethodCall
            || $node instanceof Expr\StaticCall
            || $node instanceof Stmt\If_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Return_
            || $node instanceof Expr\Throw_) as $node) {
            $tokens[] = $this->tokenFor($node);
        }

        // Joined tokens are the grouping key: two tests with an identical sequence collide into one candidate group.
        return implode('|', $tokens);
    }

    /**
     * Convert a structural AST node to a stable fingerprint token.
     *
     * @param Node $node One structural node from a test body; call targets fold into the token, plain shapes do not.
     *
     * @return string Fingerprint token.
     */
    private function tokenFor(Node $node): string
    {
        if ($node instanceof Expr\New_) {
            $class = $node->class instanceof Name ? $node->class->toString() : 'expr';

            // Fold the constructed class in so `new A` and `new B` fingerprint as distinct shapes.
            return 'new:' . $class;
        }

        if ($node instanceof Expr\FuncCall) {
            // Key on the function name so differing calls separate; dynamic targets collapse to a stable placeholder.
            return 'func:' . (TestQualityNodeHelper::functionName($node) ?? 'expr');
        }

        if ($node instanceof Expr\MethodCall) {
            // Key on the method name; an unresolved dynamic call collapses to the same placeholder for stability.
            return 'method:' . (TestQualityNodeHelper::callName($node) ?? 'expr');
        }

        if ($node instanceof Expr\StaticCall) {
            $class = $node->class instanceof Name ? $node->class->toString() : 'expr';

            // Fold both class and method so distinct static calls stay distinguishable in the fingerprint.
            return 'static:' . $class . '::' . (TestQualityNodeHelper::callName($node) ?? 'expr');
        }

        // Control-flow nodes (if/foreach/return/throw) carry no comparable name, so their class alone is the token.
        return $node::class;
    }
}
