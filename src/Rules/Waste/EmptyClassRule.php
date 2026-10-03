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
use GruffPhp\Support\DeclarationLine;
use PhpParser\Node\Stmt\Class_;

/**
 * Flags a concrete class with no members at all, catching a leftover stub or placeholder - while
 * exempting marker exception subtypes, whose empty body is the whole point.
 *
 * Runs per file over every class, skipping abstract and anonymous ones. A class with an empty body that
 * does not extend an Exception/Throwable type is reported at advisory, since an empty class is sometimes
 * a deliberate stub.
 */
final readonly class EmptyClassRule implements RuleInterface
{
    /**
     * Stable rule identifier for empty class findings.
     */
    public const ID = 'waste.empty-class';

    /**
     * Describes the empty-class rule for the registry and reports.
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A deliberate empty marker class that is not an exception, such as an attribute class, a DI tag, or an empty test double.',
                    'mitigation' => 'Only subtypes whose parent name ends in Exception or Throwable are exempt, so accept the advisory for other marker types.',
                ],
            ],
        );
    }

    /**
     * Reports each concrete, member-less class that is not an exception marker.
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

        // Check each class in the file.
        foreach ($classes as $class) {
            // Abstract and anonymous classes are exempt; an empty body is legitimate there.
            if ($class->isAbstract() || $class->isAnonymous()) {
                continue;
            }

            // A class with any members is not empty.
            if ($class->stmts !== []) {
                continue;
            }

            // Marker exception subtypes are meant to be empty, so skip them.
            if ($this->isEmptyExceptionMarker($class)) {
                continue;
            }

            $symbol = $class->name?->toString() ?? sprintf('class@anonymous:%d', $class->getStartLine());

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s is an empty class with no members.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        DeclarationLine::of($class),
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
     * Reports whether an empty class is a marker exception subtype, whose empty body is intentional.
     *
     * @param Class_ $class - Class declaration to test; only a parent type can make an empty body legitimate.
     *
     * @return bool - True when the class extends an exception/throwable type.
     */
    private function isEmptyExceptionMarker(Class_ $class): bool
    {
        // No parent means it cannot be a marker subtype, so an empty body is not excused.
        if ($class->extends === null) {
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
