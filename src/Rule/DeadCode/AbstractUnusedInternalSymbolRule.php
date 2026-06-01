<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\ProjectRuleAccumulator;
use GruffPhp\Rule\ProjectRuleInterface;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;

/**
 * Shared accumulator implementation for project-wide dead-code symbol rules.
 */
abstract class AbstractUnusedInternalSymbolRule implements ProjectRuleInterface, ProjectRuleAccumulator
{
    /**
     * Default namespace prefixes treated as external extension contracts.
     */
    private const DEFAULT_EXTERNAL_PREFIXES = [
        'Psr\\',
        'Symfony\\',
        'Doctrine\\',
        'Twig\\',
        'League\\',
        'PhpParser\\',
        'PHPUnit\\',
    ];

    /**
     * Attribute prefixes that mark framework entrypoints.
     */
    private const DEFAULT_FRAMEWORK_ATTRIBUTE_PREFIXES = [
        'Symfony\\',
        'Doctrine\\',
        'Attribute\\AsCommand',
        'Attribute\\AsController',
        'Attribute\\AutoconfigureTag',
        'Attribute\\AsEventSubscriber',
    ];

    /**
     * Project symbol index for the current analysis pass.
     */
    private DeadCodeProjectIndex $index;

    /**
     * Build the rule with a fresh index.
     */
    public function __construct()
    {
        $this->index = new DeadCodeProjectIndex();
    }

    /**
     * Describe this project-wide dead-code rule.
     *
     * @return RuleDefinition - identity, advisory/medium defaults, and the shared project-wide dead-code options
     */
    public function definition(): RuleDefinition
    {
        // Project-wide reachability is advisory/medium by default because reflection, frameworks, and external callers may hide entrypoints.
        return new RuleDefinition(
            id:                  $this->id(),
            name:                $this->name(),
            pillar:              Pillar::DeadCode,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Advisory,
            confidence:          Confidence::Medium,
            defaultOptions:      [
                                     'internalNamespacePrefixes'  => [],
                                     'entrypointSymbols'          => [],
                                     'entrypointPathPrefixes'     => [],
                                     'additionalExcludedPaths'    => [],
                                     'externalNamespacePrefixes'  => self::DEFAULT_EXTERNAL_PREFIXES,
                                     'frameworkAttributePrefixes' => self::DEFAULT_FRAMEWORK_ATTRIBUTE_PREFIXES,
                                     'treatTestsAsReferences'    => true,
                                 ],
            description:         $this->description(),
            optionDescriptions:  [
                                     'internalNamespacePrefixes'  => 'Namespace prefixes treated as project-owned when composer.json PSR-4 prefixes are absent or too broad.',
                                     'entrypointSymbols'          => 'Exact FQNs externally invoked by frameworks, CLIs, routes, or reflection.',
                                     'entrypointPathPrefixes'     => 'Path prefixes whose declarations are entrypoints and should not emit dead-code findings.',
                                     'additionalExcludedPaths'    => 'Path prefixes excluded from declaration and reference indexing for this rule.',
                                     'externalNamespacePrefixes'  => 'Namespace prefixes treated as external contracts even when reachable from project files.',
                                     'frameworkAttributePrefixes' => 'Attribute prefixes that mark declarations as framework entrypoints.',
                                     'treatTestsAsReferences'     => 'Whether references from test files keep production symbols live.',
                                 ],
            falsePositiveShapes: [
                                     [
                                         'shape'      => 'Framework, container, route, plugin, or reflection entrypoint invoked outside supported AST reference shapes.',
                                         'mitigation' => 'Add the symbol to entrypointSymbols or the file to entrypointPathPrefixes.',
                                     ],
                                 ],
        );
    }

    /**
     * Analyse all project units for unused internal symbols.
     *
     * @param list<AnalysisUnit> $units       Parsed project units.
     * @param RuleContext        $ruleContext Rule context carrying config.
     *
     * @return list<Finding> - project-wide dead-code findings; empty when no candidate symbol is provably unreferenced
     */
    public function analyseProject(array $units, RuleContext $ruleContext): array
    {
        $this->startProject($ruleContext);
        foreach ($units as $unit) {
            $this->accumulate($unit, $ruleContext);
        }

        return $this->finishProject($ruleContext);
    }

    /**
     * Reset the symbol index for a streaming project pass.
     *
     * @param RuleContext $ruleContext Rule context carrying config.
     *
     * @return void
     */
    public function startProject(RuleContext $ruleContext): void
    {
        $definition = $this->definition();
        $this->index->start($ruleContext, $definition);
    }

    /**
     * Accumulate declaration/reference summaries from one unit.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to index.
     * @param RuleContext  $ruleContext  Rule context; accepted for the accumulator interface.
     *
     * @return void
     */
    public function accumulate(AnalysisUnit $analysisUnit, RuleContext $ruleContext): void
    {
        if ($analysisUnit->hasParseErrors()) {
            return;
        }

        $this->index->accumulate($analysisUnit);
    }

    /**
     * Emit findings from the accumulated symbol index and clear it.
     *
     * @param RuleContext $ruleContext Rule context carrying config.
     *
     * @return list<Finding> - one finding per unused declaration for this rule's symbol kind; empty when all candidates are referenced
     */
    public function finishProject(RuleContext $ruleContext): array
    {
        $definition   = $this->definition();
        $declarations = $this->unusedDeclarations();
        $this->index->clear();

        $findings = [];
        foreach ($declarations as $declaration) {
            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     $this->messageFor($declaration),
                filePath:    $declaration->displayPath,
                line:        $declaration->line,
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $declaration->fqn,
                remediation: 'Remove the declaration if unused, or configure entrypointSymbols / entrypointPathPrefixes when the symbol is externally invoked.',
                metadata:    [
                                 'symbolFqn'     => $declaration->fqn,
                                 'symbolKind'    => $declaration->kind,
                                 'referenceCount' => 0,
                             ],
            );
        }

        // Findings are sorted globally by RuleRegistry after every rule contributes its list.
        return $findings;
    }

    /**
     * Rule identifier supplied by each concrete rule.
     *
     * @return string - stable rule id
     */
    abstract protected function id(): string;

    /**
     * Human-readable rule name supplied by each concrete rule.
     *
     * @return string - rule-list display name
     */
    abstract protected function name(): string;

    /**
     * Rule-list description supplied by each concrete rule.
     *
     * @return string - longer description for list-rules output
     */
    abstract protected function description(): string;

    /**
     * Symbol family selected by this rule.
     *
     * @return string - class-like, function, or constant
     */
    abstract protected function symbolFamily(): string;

    /**
     * Select this rule's unused declarations from the shared index.
     *
     * @return list<DeadCodeSymbolDeclaration> - declarations this rule should report
     */
    private function unusedDeclarations(): array
    {
        $family = $this->symbolFamily();

        return match ($family) {
            'class-like' => $this->index->unusedClassLikeDeclarations(),
            'function'   => $this->index->unusedFunctionDeclarations(),
            'constant'   => $this->index->unusedConstantDeclarations(),
            default      => [],
        };
    }

    /**
     * Build a finding message for one unused declaration.
     *
     * @param DeadCodeSymbolDeclaration $declaration Unused declaration.
     *
     * @return string - human-facing finding message
     */
    private function messageFor(DeadCodeSymbolDeclaration $declaration): string
    {
        $family = $this->symbolFamily();

        if ($family === 'class-like') {
            return sprintf('Internal %s %s is never referenced by supported static shapes.', $declaration->kind, $declaration->fqn);
        }

        if ($family === 'function') {
            return sprintf('Internal function %s() is never called by supported static shapes.', $declaration->fqn);
        }

        return sprintf('Internal constant %s is never fetched by supported static shapes.', $declaration->fqn);
    }
}
