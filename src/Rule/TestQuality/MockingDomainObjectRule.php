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

/**
 * Detects mocks for domain objects where real values keep tests clearer.
 */
final readonly class MockingDomainObjectRule implements RuleInterface
{
    /**
     * Stable rule identifier for mocked domain object findings.
     */
    public const ID = 'test-quality.mocking-domain-object';

    /**
     * Describe the mocking-domain-object rule.
     *
     * @return RuleDefinition Rule metadata, defaults, and options.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Mocking a domain object',
            pillar:             Pillar::TestQuality,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::Low,
            isEnabledByDefault: true,
            defaultOptions:     ['domainNamespaces' => []],
        );
    }

    /**
     * Find mock creations for classes that match configured domain-object patterns.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for mocked domain objects.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $patterns = $ruleContext->settingsFor($this->definition())->stringListOption('domainNamespaces');
        if ($patterns === []) {
            return [];
        }

        $useAliases = $this->collectUseAliases($analysisUnit);
        $findings   = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                if (!TestQualityNodeHelper::isMockCreationCall($call)) {
                    continue;
                }

                $className = $this->classNameArg($call, 0);
                if ($className === null) {
                    continue;
                }

                $resolved = $this->resolveClassName($className, $useAliases);
                $matched  = $this->matchesAnyPattern($resolved, $patterns);

                if ($matched === null) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:  self::ID,
                    message: sprintf(
                        '%s mocks %s, which matches the configured domain-object pattern "%s".',
                        $scope->symbol,
                        $resolved,
                        $matched,
                    ),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $call->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Low,
                    symbol:      $scope->symbol,
                    remediation: 'Domain objects usually carry behaviour worth exercising directly. Construct the real instance, or move the boundary so this collaborator becomes a service interface that is safe to mock.',
                    metadata:    ['class' => $resolved, 'pattern' => $matched],
                );
            }
        }

        return $findings;
    }

    /**
     * Map imported class aliases to fully qualified names.
     *
     * @return array<string, string>
     */
    private function collectUseAliases(AnalysisUnit $analysisUnit): array
    {
        $useAliases = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Use_::class) as $use) {
            foreach ($use->uses as $useUse) {
                $alias              = $useUse->getAlias()->toString();
                $useAliases[$alias] = $useUse->name->toString();
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\GroupUse::class) as $group) {
            $prefix = $group->prefix->toString();
            foreach ($group->uses as $useUse) {
                $alias              = $useUse->getAlias()->toString();
                $useAliases[$alias] = $prefix . '\\' . $useUse->name->toString();
            }
        }

        return $useAliases;
    }

    /**
     * Extract a `ClassName::class` argument from a mock creation call.
     *
     * @return string|null Class name string, or null when absent.
     */
    private function classNameArg(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?string
    {
        $classConstFetch = TestQualityNodeHelper::argValue($call, $index);
        if (!$classConstFetch instanceof Expr\ClassConstFetch || !$classConstFetch->class instanceof Name) {
            return null;
        }

        $name = $classConstFetch->name;
        if (!$name instanceof Node\Identifier || strtolower($name->toString()) !== 'class') {
            return null;
        }

        return $classConstFetch->class->toString();
    }

    /**
     * @param array<string, string> $useAliases
     *
     * @return string Resolved class name.
     */
    private function resolveClassName(string $className, array $useAliases): string
    {
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }

        $first = explode('\\', $className, 2)[0];

        if (isset($useAliases[$first])) {
            $rest = substr($className, strlen($first));

            return $useAliases[$first] . $rest;
        }

        return $className;
    }

    /**
     * @param list<string> $patterns
     *
     * @return string|null Matching pattern, or null when none match.
     */
    private function matchesAnyPattern(string $className, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $className, FNM_NOESCAPE)) {
                return $pattern;
            }
        }

        return null;
    }
}
