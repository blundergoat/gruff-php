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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - this rule's stable identity, default Advisory severity, and the empty `domainNamespaces` option it ships with
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per mock whose target matched a configured domain-object pattern; empty when none configured or none
     *                       matched
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $patterns = $ruleContext->settingsFor($this->definition())->stringListOption('domainNamespaces');
        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($patterns === []) {
            // With no domain namespaces configured, every class is a legitimate mock target; stay silent.
            return [];
        }

        $useAliases = $this->collectUseAliases($analysisUnit);
        $findings   = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // User view: add each item that can appear in findings list.
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                // User view: choose the findings list branch for this case.
                if (!TestQualityNodeHelper::isMockCreationCall($call)) {
                    continue;
                }

                $className = $this->classNameArg($call, 0);
                // User view: choose the findings list branch for this case.
                // User view: missing data becomes the expected findings list state.
                if ($className === null) {
                    continue;
                }

                $resolved = $this->resolveClassName($className, $useAliases);
                $matched  = $this->matchesAnyPattern($resolved, $patterns);

                // User view: choose the findings list branch for this case.
                // User view: missing data becomes the expected findings list state.
                if ($matched === null) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf(
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

        return $findings;
    }

    /**
     * Map imported class aliases to fully qualified names.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose `use` and group-use statements supply the alias map.
     *
     * @return array<string, string> - local import alias keyed to its fully qualified target; empty when the unit has no `use` statements
     */
    private function collectUseAliases(AnalysisUnit $analysisUnit): array
    {
        $useAliases = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Use_::class) as $use) {
            // User view: add each item that can appear in findings list.
            foreach ($use->uses as $useUse) {
                $alias              = $useUse->getAlias()->toString();
                $useAliases[$alias] = $useUse->name->toString();
            }
        }

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\GroupUse::class) as $group) {
            $prefix = $group->prefix->toString();
            // User view: add each item that can appear in findings list.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Mock-creation call whose argument is read.
     * @param int                                           $index - Zero-based argument position holding the class.
     *
     * @return string|null - the mocked class name as written, or null when the argument is not a `Something::class` fetch
     */
    private function classNameArg(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?string
    {
        $classConstFetch = TestQualityNodeHelper::argValue($call, $index);
        // User view: choose the findings list branch for this case.
        if (!$classConstFetch instanceof Expr\ClassConstFetch || !$classConstFetch->class instanceof Name) {
            // The argument is not a `Something::class` fetch, so no class name can be recovered.
            return null;
        }

        $name = $classConstFetch->name;
        // User view: choose the findings list branch for this case.
        if (!$name instanceof Node\Identifier || strtolower($name->toString()) !== 'class') {
            // A `::CONST` other than `::class` does not name a type to mock.
            return null;
        }

        return $classConstFetch->class->toString();
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string                $className - Class reference as written at the mock site, aliased or qualified.
     * @param array<string, string> $useAliases - Import alias map used to expand a leading short segment.
     *
     * @return string - the fully qualified class name with any import alias expanded and no leading backslash
     */
    private function resolveClassName(string $className, array $useAliases): string
    {
        // User view: choose the findings list branch for this case.
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }

        $first = explode('\\', $className, 2)[0];

        // User view: choose the findings list branch for this case.
        if (isset($useAliases[$first])) {
            $rest = substr($className, strlen($first));

            // The leading segment was imported, so expand it to its fully qualified target.
            return $useAliases[$first] . $rest;
        }

        return $className;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string       $className - Fully qualified class name to test against the domain-object globs.
     * @param list<string> $patterns - fnmatch globs marking namespaces the project treats as domain objects.
     *
     * @return string|null - the first glob the class matches, or null when no configured pattern matches
     */
    private function matchesAnyPattern(string $className, array $patterns): ?string
    {
        // User view: add each item that can appear in findings list.
        foreach ($patterns as $pattern) {
            // User view: choose the findings list branch for this case.
            if (fnmatch($pattern, $className, FNM_NOESCAPE)) {
                // First glob the class matches is reported back so the finding can name which rule it tripped.
                return $pattern;
            }
        }

        return null;
    }
}
