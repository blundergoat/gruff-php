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
        // Low confidence and advisory: only fires once a project opts in by listing its own domain namespaces.
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
            // With no domain namespaces configured, every class is a legitimate mock target; stay silent.
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
                    remediation: 'Domain objects usually carry behaviour worth exercising directly. Construct the real instance, or move the boundary so this collaborator becomes a service interface that is safe to mock. If a namespace genuinely holds boundary collaborators rather than domain objects, remove it from `rules.test-quality.mocking-domain-object.options.domainNamespaces` in `.gruff-php.yaml`.',
                    metadata:    ['class' => $resolved, 'pattern' => $matched],
                );
            }
        }

        // Hand back one finding per mock whose target class matched a configured domain-object pattern.
        return $findings;
    }

    /**
     * Map imported class aliases to fully qualified names.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit whose `use` and group-use statements supply the alias map.
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

        // Local alias keyed to its fully qualified target, so short class references can be resolved later.
        return $useAliases;
    }

    /**
     * Extract a `ClassName::class` argument from a mock creation call.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call  Mock-creation call whose argument is read.
     * @param int                                           $index Zero-based argument position holding the class.
     *
     * @return string|null Class name string, or null when absent.
     */
    private function classNameArg(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?string
    {
        $classConstFetch = TestQualityNodeHelper::argValue($call, $index);
        if (!$classConstFetch instanceof Expr\ClassConstFetch || !$classConstFetch->class instanceof Name) {
            // The argument is not a `Something::class` fetch, so no class name can be recovered.
            return null;
        }

        $name = $classConstFetch->name;
        if (!$name instanceof Node\Identifier || strtolower($name->toString()) !== 'class') {
            // A `::CONST` other than `::class` does not name a type to mock.
            return null;
        }

        // The left-hand side of the `::class` fetch is the mocked class name.
        return $classConstFetch->class->toString();
    }

    /**
     * @param string                $className  Class reference as written at the mock site, aliased or qualified.
     * @param array<string, string> $useAliases Import alias map used to expand a leading short segment.
     *
     * @return string Resolved class name.
     */
    private function resolveClassName(string $className, array $useAliases): string
    {
        if (str_starts_with($className, '\\')) {
            // Already fully qualified; just drop the leading separator to match the alias-map form.
            return ltrim($className, '\\');
        }

        $first = explode('\\', $className, 2)[0];

        if (isset($useAliases[$first])) {
            $rest = substr($className, strlen($first));

            // The leading segment was imported, so expand it to its fully qualified target.
            return $useAliases[$first] . $rest;
        }

        // No matching import, so the reference is already same-namespace or global and stands as written.
        return $className;
    }

    /**
     * @param string       $className Fully qualified class name to test against the domain-object globs.
     * @param list<string> $patterns  fnmatch globs marking namespaces the project treats as domain objects.
     *
     * @return string|null Matching pattern, or null when none match.
     */
    private function matchesAnyPattern(string $className, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $className, FNM_NOESCAPE)) {
                // First glob the class matches is reported back so the finding can name which rule it tripped.
                return $pattern;
            }
        }

        // No configured pattern matched, so this class is not a domain object for the rule's purposes.
        return null;
    }
}
