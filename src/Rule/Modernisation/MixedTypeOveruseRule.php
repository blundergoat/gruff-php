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
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Detects signatures that rely on mixed where narrower types would improve contracts.
 */
final readonly class MixedTypeOveruseRule implements RuleInterface
{
    /**
     * Stable rule identifier for mixed type overuse findings.
     */
    public const ID = 'modernisation.mixed-type-overuse';

    /**
     * Describe the mixed type overuse rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Mixed type overuse',
            pillar:          Pillar::Modernisation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find parameters and returns that overuse explicit mixed types.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for broad type usage.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.0)) {
            return [];
        }

        $findings = [];

        foreach (NodeIndex::nodesOfAny($analysisUnit, [Stmt\ClassMethod::class, Stmt\Function_::class]) as $functionLike) {
            if ($functionLike instanceof Stmt\ClassMethod && !$functionLike->isPublic()) {
                continue;
            }

            if (!$functionLike instanceof Stmt\ClassMethod && !$functionLike instanceof Stmt\Function_) {
                continue;
            }

            $locations = $this->mixedLocations($functionLike);
            if ($locations === []) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('Public API uses mixed type in %s.', implode(', ', $locations)),
                filePath:    $analysisUnit->file->displayPath,
                line:        $functionLike->getStartLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::Modernisation,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                symbol:      $functionLike->name->toString() . '()',
                remediation: 'Replace `mixed` with the actual input shape. For JSON-boundary helpers (parameters consuming `json_decode` output), use `array|bool|float|int|string|null` - the supertype of any top-level decoded value. When only one input shape is meaningful, narrow further to that concrete type (`?string`, `int|float|null`, a named class).',
                metadata:    [
                    'requiresPhp' => 8.0,
                    'locations' => $locations,
                ],
            );
        }

        return $findings;
    }

    /**
     * List source locations where broad mixed types appear.
     *
     * @return list<string>
     */
    private function mixedLocations(Stmt\ClassMethod|Stmt\Function_ $functionLike): array
    {
        $locations = [];

        foreach ($functionLike->params as $parameter) {
            if (ModernisationNodeHelper::typeName($parameter->type) === 'mixed') {
                $name = $parameter->var instanceof \PhpParser\Node\Expr\Variable && is_string($parameter->var->name)
                    ? '$' . $parameter->var->name
                    : 'parameter';
                $locations[] = $name;
            }
        }

        if (ModernisationNodeHelper::typeName($functionLike->returnType) === 'mixed') {
            $locations[] = 'return type';
        }

        return $locations;
    }
}
