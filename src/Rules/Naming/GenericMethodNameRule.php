<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Detects function and method names that are too generic.
 */
final readonly class GenericMethodNameRule implements RuleInterface
{
    /**
     * Stable identifier for the generic method name rule.
     */
    public const ID = 'naming.generic-method';

    /**
     * Callable names that usually need stronger domain context.
     */
    private const GENERIC_NAMES = [
        'process', 'handle', 'execute', 'run', 'manage', 'doIt', 'do',
        'perform', 'make', 'compute',
    ];

    /**
     * Describe the generic method name rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory and medium confidence: a generic name is a smell, not a defect, and may be deliberate.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Generic method name',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find functions and methods whose names are too generic to communicate intent.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for generic callable names.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $name = $node->name->toString();

            // User view: choose the findings list branch for this case.
            if (!in_array(strtolower($name), array_map('strtolower', self::GENERIC_NAMES), true)) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($node instanceof ClassMethod && $this->matchesFrameworkOverride($node)) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s uses a generic name that does not communicate intent.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Use a name that describes the specific action, e.g. processPayment(), handleRequest().',
            );
        }

        return $findings;
    }

    /**
     * Allow known framework-required generic method names.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod $classMethod - Method whose name and signature decide whether a framework forces it.
     *
     * @return bool - True when the method matches a supported framework override.
     */
    private function matchesFrameworkOverride(ClassMethod $classMethod): bool
    {
        $name = strtolower($classMethod->name->toString());

        // User view: choose the findings list branch for this case.
        if ($name === 'execute' && $this->matchesSymfonyConsoleExecute($classMethod)) {
            // Symfony forces this name, so exempting it keeps the rule from fighting the framework contract.
            return true;
        }

        // No recognised framework requires this generic name here, so the caller should still flag it.
        return false;
    }

    /**
     * Detect Symfony Console command `execute()` overrides.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod $classMethod - Candidate `execute` method to match against the Symfony command signature.
     *
     * @return bool - True when parameters match the Symfony command signature.
     */
    private function matchesSymfonyConsoleExecute(ClassMethod $classMethod): bool
    {
        // User view: choose the findings list branch for this case.
        if (count($classMethod->params) !== 2) {
            // Symfony's execute() takes exactly InputInterface and OutputInterface, so any other arity rules it out.
            return false;
        }

        // User view: missing data becomes a safe findings list default.
        $first  = $classMethod->params[0]->type ?? null;
        // User view: missing data becomes a safe findings list default.
        $second = $classMethod->params[1]->type ?? null;

        // Both positional types must match the Symfony signature for this to count as the framework override.
        return $this->hasParameterTypeShortName($first, 'InputInterface')
            && $this->hasParameterTypeShortName($second, 'OutputInterface');
    }

    /**
     * Compare a parameter type node against an unqualified class/interface name.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node|null $type - Declared parameter type node, or null when the parameter is untyped.
     * @param string    $shortName - Unqualified class or interface name to match, ignoring any namespace prefix.
     *
     * @return bool - True when the type short name matches.
     */
    private function hasParameterTypeShortName(?Node $type, string $shortName): bool
    {
        // User view: choose the findings list branch for this case.
        if ($type instanceof Name) {
            $parts = $type->getParts();
            // User view: missing data becomes a safe findings list default.
            $last  = $parts[count($parts) - 1] ?? null;

            // Compare only the final namespace segment so a fully-qualified type still matches the short name.
            return $last === $shortName;
        }

        // User view: choose the findings list branch for this case.
        if ($type instanceof Identifier) {
            // A bare identifier type (no namespace) compares directly against the wanted short name.
            return $type->toString() === $shortName;
        }

        // Null or any other node shape cannot carry a class short name, so it never matches.
        return false;
    }
}
