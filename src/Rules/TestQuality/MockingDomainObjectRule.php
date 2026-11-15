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
 * Flags mock-creation calls whose ClassName::class targets match configured domain-object globs.
 * Real instances can expose domain behaviour that mocks hide; matching does not distinguish classes from interfaces.
 * Empty domainNamespaces means no findings. Findings are advisory with low confidence.
 */
final readonly class MockingDomainObjectRule implements RuleInterface
{
    /**
     * Stable rule identifier for mocked domain object findings.
     */
    public const ID = 'test-quality.mocking-domain-object';

    /**
     * Describes the mocking-domain-object rule for the registry and reports.
     *
     * @return RuleDefinition - this rule's stable identity, default Advisory severity, and the empty domainNamespaces option it ships with
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A configured domain namespace that also holds interfaces or ports which are meant to be doubled.',
                    'mitigation' => 'Matching is by namespace prefix and does not separate value objects from ports, so narrow options.domainNamespaces to the value-object namespaces only.',
                ],
            ],
        );
    }

    /**
     * Reports mock creations for classes that match configured domain-object patterns.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Supplies domainNamespaces; with none configured the rule reports nothing.
     *
     * @return list<Finding> - One finding per matching mock target; empty when no patterns or targets match.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $patterns = $ruleContext->settingsFor($this->definition())->stringListOption('domainNamespaces');
        if ($patterns === []) {
            // Without configured patterns, this rule has no domain targets to report.
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
     * Maps imported class aliases to their fully qualified names.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose `use` and group-use statements supply the alias map.
     *
     * @return array<string, string> - local import alias keyed to its fully qualified target; empty when the unit has no `use` statements
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
     * Returns the class named by a ClassName::class argument, or null.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call treated by this rule as mock creation; its selected argument is read.
     * @param int                                           $index - Zero-based argument position holding the class.
     *
     * @return string|null - Class name written in the selected ::class argument, or null when that argument is not a named class fetch.
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
     * Expands a class reference to its fully qualified name using the import aliases.
     *
     * @param string                $className - Class reference as written at the mock site, aliased or qualified.
     * @param array<string, string> $useAliases - Import alias map used to expand a leading short segment.
     *
     * @return string - the fully qualified class name with any import alias expanded and no leading backslash
     */
    private function resolveClassName(string $className, array $useAliases): string
    {
        // A leading backslash already means a fully qualified name.
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
     * Returns the first domain-object glob the class matches, or null.
     *
     * @param string       $className - Fully qualified class name to test against the domain-object globs.
     * @param list<string> $patterns - fnmatch globs marking namespaces the project treats as domain objects.
     *
     * @return string|null - the first glob the class matches, or null when no configured pattern matches
     */
    private function matchesAnyPattern(string $className, array $patterns): ?string
    {
        // FNM_NOESCAPE keeps namespace backslashes literal in the glob.
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $className, FNM_NOESCAPE)) {

                return $pattern;
            }
        }

        return null;
    }
}
