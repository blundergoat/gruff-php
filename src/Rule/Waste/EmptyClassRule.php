<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Waste;

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
use PhpParser\Node\Stmt\Class_;

/**
 * Detects concrete classes that declare no members and are not marker exceptions.
 */
final readonly class EmptyClassRule implements RuleInterface
{
    /**
     * Stable rule identifier for empty class findings.
     */
    public const ID = 'waste.empty-class';

    /**
     * Describe the empty class rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory and medium confidence: an empty class is sometimes a deliberate stub, so this only nudges.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Empty class',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find concrete classes that declare no members and are not exception markers.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per concrete, member-less class that is not an exception marker.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $classes    = NodeIndex::nodesOf($analysisUnit, Class_::class);

        $findings = [];

        foreach ($classes as $class) {
            if ($class->isAbstract() || $class->isAnonymous()) {
                continue;
            }

            if ($class->stmts !== []) {
                continue;
            }

            if ($this->isEmptyExceptionMarker($class)) {
                continue;
            }

            $symbol = $class->name?->toString() ?? sprintf('class@anonymous:%d', $class->getStartLine());

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s is an empty class with no members.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $class->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                endLine:     $class->getEndLine() > 0 ? $class->getEndLine() : null,
                symbol:      $symbol,
                remediation: 'Add members or remove the class if it serves no purpose.',
            );
        }

        return $findings;
    }

    /**
     * Allow empty classes that exist as exception marker types.
     *
     * @param Class_ $class - Class declaration to test; only a parent type can make an empty body legitimate.
     *
     * @return bool - True when the class extends an exception/throwable type.
     */
    private function isEmptyExceptionMarker(Class_ $class): bool
    {
        if ($class->extends === null) {
            // No parent means it cannot be a marker subtype, so an empty body is not excused.
            return false;
        }

        $parent = $class->extends->toString();

        // Marker exceptions add nothing but a distinct type; an empty body is the intended shape, so exempt them.
        return $parent === 'Exception'
            || $parent === 'Throwable'
            || str_ends_with($parent, 'Exception')
            || str_ends_with($parent, 'Throwable')
            || str_ends_with($parent, '\\Exception')
            || str_ends_with($parent, '\\Throwable');
    }
}
