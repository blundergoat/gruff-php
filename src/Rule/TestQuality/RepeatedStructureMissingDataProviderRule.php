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
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for repeated test structures.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings   = $context->settingsFor($definition);

        if ($this->isPathIgnored($unit->file->displayPath, $settings->stringListOption('ignoredPathPatterns'))) {
            return [];
        }

        $finder   = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Stmt\Class_::class) as $class) {
            $className = $class->name?->toString();
            if ($className === null) {
                continue;
            }

            $groups = [];

            foreach ($class->getMethods() as $method) {
                if (!TestQualityNodeHelper::isTestMethod($method)) {
                    continue;
                }

                if ($this->usesDataProvider($method)) {
                    continue;
                }

                $stmts = array_values($method->stmts ?? []);
                if (count($stmts) < 2) {
                    continue;
                }

                $shape = $this->fingerprint($stmts, $finder);
                $groups[$shape] ??= [];
                $groups[$shape][] = $method;
            }

            foreach ($groups as $methods) {
                if (count($methods) < self::MIN_GROUP_SIZE) {
                    continue;
                }

                $names = array_map(static fn (Stmt\ClassMethod $method): string => $method->name->toString(), $methods);
                $first = $methods[0];

                $findings[] = new Finding(
                    ruleId:  self::ID,
                    message: sprintf(
                        '%s has %d structurally identical test methods (%s) that look like a data provider would replace.',
                        $className,
                        count($methods),
                        implode(', ', $names),
                    ),
                    filePath:    $unit->file->displayPath,
                    line:        $first->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Low,
                    symbol:      sprintf('%s::%s()', $className, $first->name->toString()),
                    remediation: 'Collapse the repeated tests into one method driven by #[DataProvider] or @dataProvider with the differing values as data rows.',
                    metadata:    ['count' => count($methods), 'methods' => $names],
                );
            }
        }

        return $findings;
    }

    /**
     * Check whether a project-configured path exemption applies.
     *
     * @param list<string> $patterns Glob patterns for accepted test shapes.
     * @return bool True when the display path matches an ignored pattern.
     */
    private function isPathIgnored(string $displayPath, array $patterns): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a test method already declares a data provider.
     *
     * @return bool True when an attribute or docblock data provider is present.
     */
    private function usesDataProvider(Stmt\ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (strtolower($attr->name->getLast()) === 'dataprovider') {
                    return true;
                }
            }
        }

        return str_contains($method->getDocComment()?->getText() ?? '', '@dataProvider');
    }

    /**
     * @param list<Node> $stmts
     *
     * @return string Structure fingerprint for comparison across tests.
     */
    private function fingerprint(array $stmts, NodeFinder $finder): string
    {
        $tokens = [];

        foreach ($finder->find($stmts, static fn (Node $node): bool => $node instanceof Expr\New_
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

        return implode('|', $tokens);
    }

    /**
     * Convert a structural AST node to a stable fingerprint token.
     *
     * @return string Fingerprint token.
     */
    private function tokenFor(Node $node): string
    {
        if ($node instanceof Expr\New_) {
            $class = $node->class instanceof Name ? $node->class->toString() : 'expr';

            return 'new:' . $class;
        }

        if ($node instanceof Expr\FuncCall) {
            return 'func:' . (TestQualityNodeHelper::functionName($node) ?? 'expr');
        }

        if ($node instanceof Expr\MethodCall) {
            return 'method:' . (TestQualityNodeHelper::callName($node) ?? 'expr');
        }

        if ($node instanceof Expr\StaticCall) {
            $class = $node->class instanceof Name ? $node->class->toString() : 'expr';

            return 'static:' . $class . '::' . (TestQualityNodeHelper::callName($node) ?? 'expr');
        }

        return $node::class;
    }
}
