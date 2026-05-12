<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Modernisation;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

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
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for enum candidate classes.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        if (!ModernisationNodeHelper::supportsPhp($context, 8.1)) {
            return [];
        }

        $finder   = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Stmt\Class_::class) as $class) {
            $constants = $class->getConstants();
            if (count($constants) < 2 || $class->getProperties() !== [] || $class->getMethods() !== [] || $class->extends !== null) {
                continue;
            }

            if (!$this->allScalarConstants($constants)) {
                continue;
            }

            $className  = ModernisationNodeHelper::className($class) ?? 'anonymous class';
            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('Class %s only contains scalar constants and may be an enum candidate.', $className),
                filePath:    $unit->file->displayPath,
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
     * @return bool True when every constant value is a string or integer scalar.
     */
    private function allScalarConstants(array $constants): bool
    {
        foreach ($constants as $constantGroup) {
            foreach ($constantGroup->consts as $constant) {
                if (!$constant->value instanceof Scalar\String_ && !$constant->value instanceof Scalar\Int_) {
                    return false;
                }
            }
        }

        return true;
    }
}
