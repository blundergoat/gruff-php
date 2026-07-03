<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Waste;

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
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: add each item that can appear in findings list.
        foreach ($classes as $class) {
            // User view: choose the findings list branch for this case.
            if ($class->isAbstract() || $class->isAnonymous()) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if ($class->stmts !== []) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($this->isEmptyExceptionMarker($class)) {
                continue;
            }

            // User view: missing data becomes a safe findings list default.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Class_ $class - Class declaration to test; only a parent type can make an empty body legitimate.
     *
     * @return bool - True when the class extends an exception/throwable type.
     */
    private function isEmptyExceptionMarker(Class_ $class): bool
    {
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
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
