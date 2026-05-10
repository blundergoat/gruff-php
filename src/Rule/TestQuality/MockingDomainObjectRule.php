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

final readonly class MockingDomainObjectRule implements RuleInterface
{
    public const ID = 'test-quality.mocking-domain-object';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Mocking a domain object',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Low,
            defaultEnabled: false,
            defaultOptions: ['domainNamespaces' => []],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $patterns = $context->settingsFor($this->definition())->stringListOption('domainNamespaces');
        if ($patterns === []) {
            return [];
        }

        $finder = new NodeFinder();
        $useMap = $this->collectUseMap($unit, $finder);
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                if (!TestQualityNodeHelper::isMockCreationCall($call)) {
                    continue;
                }

                $className = $this->classNameArg($call, 0);
                if ($className === null) {
                    continue;
                }

                $resolved = $this->resolveClassName($className, $useMap);
                $matched = $this->matchesAnyPattern($resolved, $patterns);

                if ($matched === null) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId: self::ID,
                    message: sprintf(
                        '%s mocks %s, which matches the configured domain-object pattern "%s".',
                        $scope->symbol,
                        $resolved,
                        $matched,
                    ),
                    filePath: $unit->file->displayPath,
                    line: $call->getStartLine(),
                    severity: Severity::Advisory,
                    pillar: Pillar::TestQuality,
                    tier: RuleTier::V01,
                    confidence: Confidence::Low,
                    symbol: $scope->symbol,
                    remediation: 'Domain objects usually carry behaviour worth exercising directly. Construct the real instance, or move the boundary so this collaborator becomes a service interface that is safe to mock.',
                    metadata: ['class' => $resolved, 'pattern' => $matched],
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function collectUseMap(AnalysisUnit $unit, NodeFinder $finder): array
    {
        $map = [];

        foreach ($finder->findInstanceOf($unit->statements, Stmt\Use_::class) as $use) {
            foreach ($use->uses as $useUse) {
                $alias = $useUse->getAlias()->toString();
                $map[$alias] = $useUse->name->toString();
            }
        }

        foreach ($finder->findInstanceOf($unit->statements, Stmt\GroupUse::class) as $group) {
            $prefix = $group->prefix->toString();
            foreach ($group->uses as $useUse) {
                $alias = $useUse->getAlias()->toString();
                $map[$alias] = $prefix . '\\' . $useUse->name->toString();
            }
        }

        return $map;
    }

    private function classNameArg(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?string
    {
        $value = TestQualityNodeHelper::argValue($call, $index);
        if (!$value instanceof Expr\ClassConstFetch || !$value->class instanceof Name) {
            return null;
        }

        $name = $value->name;
        if (!$name instanceof Node\Identifier || strtolower($name->toString()) !== 'class') {
            return null;
        }

        return $value->class->toString();
    }

    /**
     * @param array<string, string> $useMap
     */
    private function resolveClassName(string $className, array $useMap): string
    {
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }

        $first = explode('\\', $className, 2)[0];

        if (isset($useMap[$first])) {
            $rest = substr($className, strlen($first));

            return $useMap[$first] . $rest;
        }

        return $className;
    }

    /**
     * @param list<string> $patterns
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
