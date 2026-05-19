<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for generic callable names.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $nodes      = $nodeFinder->find($analysisUnit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $name = $node->name->toString();

            if (!in_array(strtolower($name), array_map('strtolower', self::GENERIC_NAMES), true)) {
                continue;
            }

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
     * @return bool True when the method matches a supported framework override.
     */
    private function matchesFrameworkOverride(ClassMethod $method): bool
    {
        $name = strtolower($method->name->toString());

        if ($name === 'execute' && $this->matchesSymfonyConsoleExecute($method)) {
            return true;
        }

        return false;
    }

    /**
     * Detect Symfony Console command `execute()` overrides.
     *
     * @return bool True when parameters match the Symfony command signature.
     */
    private function matchesSymfonyConsoleExecute(ClassMethod $method): bool
    {
        if (count($method->params) !== 2) {
            return false;
        }

        $first  = $method->params[0]->type ?? null;
        $second = $method->params[1]->type ?? null;

        return $this->hasParameterTypeShortName($first, 'InputInterface')
            && $this->hasParameterTypeShortName($second, 'OutputInterface');
    }

    /**
     * Compare a parameter type node against an unqualified class/interface name.
     *
     * @return bool True when the type short name matches.
     */
    private function hasParameterTypeShortName(?Node $type, string $shortName): bool
    {
        if ($type instanceof Name) {
            $parts = $type->getParts();
            $last  = $parts[count($parts) - 1] ?? null;

            return $last === $shortName;
        }

        if ($type instanceof Identifier) {
            return $type->toString() === $shortName;
        }

        return false;
    }
}
