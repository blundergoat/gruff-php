<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Modernisation;

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
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Detects constant-only classes that could be represented as enums.
 */
final readonly class EnumCandidateRule implements RuleInterface
{
    /**
     * Stable rule identifier for enum candidate findings.
     */
    public const ID = 'modernisation.enum-candidate';

    /**
     * Describe the enum candidate modernisation rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Enum candidate',
            pillar:          Pillar::Modernisation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find string or integer constant groups that could become enums.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for enum candidate classes.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.1)) {
            return [];
        }

        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $constants = $class->getConstants();
            if (count($constants) < 2 || $class->getProperties() !== [] || $class->getMethods() !== [] || $class->extends !== null) {
                continue;
            }

            if (!$this->allConstantsShareOneBackedEnumType($constants)) {
                continue;
            }

            $className  = ModernisationNodeHelper::className($class) ?? 'anonymous class';
            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('Class %s only contains scalar constants and may be an enum candidate.', $className),
                filePath:    $analysisUnit->file->displayPath,
                line:        $class->getStartLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::Modernisation,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                symbol:      $className,
                remediation: 'Consider a backed enum only after checking consumers and serialization contracts; gruff-php does not rewrite code.',
                metadata:    [
                    'requiresPhp' => 8.1,
                ],
            );
        }

        return $findings;
    }

    /**
     * @param list<Stmt\ClassConst> $constants
     * @return bool True when every constant value is a string-or-integer scalar AND every constant in the group is of the same backed-enum scalar type.
     */
    private function allConstantsShareOneBackedEnumType(array $constants): bool
    {
        $observedScalarTypes = [];

        foreach ($constants as $constantGroup) {
            foreach ($constantGroup->consts as $constant) {
                if ($constant->value instanceof Scalar\String_) {
                    $observedScalarTypes['string'] = true;
                } elseif ($constant->value instanceof Scalar\Int_) {
                    $observedScalarTypes['int'] = true;
                } else {
                    return false;
                }
            }
        }

        return count($observedScalarTypes) === 1;
    }
}
