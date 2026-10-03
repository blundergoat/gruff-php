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
use GruffPhp\Support\DeclarationLine;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a function or method whose name appears in a configurable replacement list of vague verbs - `process`,
 * `handle`, `run`, `execute`, and the like by default - because such names say that something happens
 * without saying what, forcing the reader into the body.
 *
 * Framework-mandated names, such as a Symfony Console command's `execute()`, are exempt so the rule does not
 * fight contracts the author cannot rename. Advisory, medium confidence.
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
    private const DEFAULT_GENERIC_NAMES = [
        'process', 'handle', 'execute', 'run', 'manage', 'doIt', 'do',
        'perform', 'make', 'compute',
    ];

    /**
     * Describes the generic-method-name rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory and medium confidence: a generic name is a smell, not a defect, and may be deliberate.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Generic method name',
            pillar:             Pillar::Naming,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::Medium,
            defaultOptions:     ['genericNames' => self::DEFAULT_GENERIC_NAMES],
            description:        'Flags functions and methods whose names match the configured replacement list of vague verbs.',
            optionDescriptions: [
                'genericNames' => 'Replacement list of function and method names treated as generic; matching is case-insensitive.',
            ],
            falsePositiveShapes: [
                [
                    'shape'      => 'An interface-mandated verb the author cannot rename, such as a PSR-15 handle() or process(), or a queue job\'s handle().',
                    'mitigation' => 'Only Symfony Console\'s execute(InputInterface, OutputInterface) is recognised as a framework override, so remove the mandated name from options.genericNames.',
                ],
            ],
        );
    }

    /**
     * Reports a function or method whose name is too generic to convey intent.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for generic callable names.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition   = $this->definition();
        $nodes        = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);
        $genericNames = $this->normalisedGenericNames(
            $ruleContext->settingsFor($definition)->stringListOption('genericNames'),
        );

        $findings = [];

        // Check every function and method in the file.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $name = $node->name->toString();

            // Only the known vague verbs are candidates for this rule.
            if (!in_array(strtolower($name), $genericNames, true)) {
                continue;
            }

            // A framework-required name such as Symfony's execute() is left alone.
            if ($node instanceof ClassMethod && $this->matchesFrameworkOverride($node)) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s uses a generic name that does not communicate intent.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        DeclarationLine::of($node),
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
     * Normalizes configured generic names for case-insensitive matching.
     *
     * @param list<string> $genericNames - Replacement list supplied by effective rule settings.
     *
     * @return list<string> - Lowercase, trimmed, de-duplicated names with blank entries dropped.
     */
    private function normalisedGenericNames(array $genericNames): array
    {
        // Trim before matching so a YAML entry indented or padded by hand still matches a callable
        // name, and drop the blanks that leaves behind; `additionalFunctions` normalises the same way.
        return array_values(array_unique(array_filter(array_map(
            static fn (string $genericName): string => strtolower(trim($genericName)),
            $genericNames,
        ), static fn (string $genericName): bool => $genericName !== '')));
    }

    /**
     * Reports whether a framework requires this otherwise-generic method name.
     *
     * @param ClassMethod $classMethod - Method whose name and signature decide whether a framework forces it.
     *
     * @return bool - True when the method matches a supported framework override.
     */
    private function matchesFrameworkOverride(ClassMethod $classMethod): bool
    {
        $name = strtolower($classMethod->name->toString());

        if ($name === 'execute' && $this->matchesSymfonyConsoleExecute($classMethod)) {
            // Symfony forces this name, so exempting it keeps the rule from fighting the framework contract.
            return true;
        }

        // No recognised framework requires this generic name here, so the caller should still flag it.
        return false;
    }

    /**
     * Reports whether a method matches the Symfony Console command `execute()` signature.
     *
     * @param ClassMethod $classMethod - Candidate `execute` method to match against the Symfony command signature.
     *
     * @return bool - True when parameters match the Symfony command signature.
     */
    private function matchesSymfonyConsoleExecute(ClassMethod $classMethod): bool
    {
        if (count($classMethod->params) !== 2) {
            // Symfony's execute() takes exactly InputInterface and OutputInterface, so any other arity rules it out.
            return false;
        }

        $first  = $classMethod->params[0]->type ?? null;
        $second = $classMethod->params[1]->type ?? null;

        // Both positional types must match the Symfony signature for this to count as the framework override.
        return $this->hasParameterTypeShortName($first, 'InputInterface')
            && $this->hasParameterTypeShortName($second, 'OutputInterface');
    }

    /**
     * Reports whether a parameter type node matches an unqualified class or interface name.
     *
     * @param Node|null $type      - Declared parameter type node, or null when the parameter is untyped.
     * @param string    $shortName - Unqualified class or interface name to match, ignoring any namespace prefix.
     *
     * @return bool - True when the type short name matches.
     */
    private function hasParameterTypeShortName(?Node $type, string $shortName): bool
    {
        if ($type instanceof Name) {
            $parts = $type->getParts();
            $last  = $parts[count($parts) - 1] ?? null;

            // Compare only the final namespace segment so a fully-qualified type still matches the short name.
            return $last === $shortName;
        }

        if ($type instanceof Identifier) {
            // A bare identifier type (no namespace) compares directly against the wanted short name.
            return $type->toString() === $shortName;
        }

        // Null or any other node shape cannot carry a class short name, so it never matches.
        return false;
    }
}
