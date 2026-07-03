<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Modernisation;

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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - registry identity for this rule, fixed at Advisory severity and Medium
     *   confidence so narrowing mixed reads as a contract suggestion rather than a build-breaking failure
     */
    public function definition(): RuleDefinition
    {
        // Advisory at medium confidence: narrowing mixed is a contract improvement to weigh, not a build-breaker.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per public function-like that declares mixed on a parameter or
     *   return; empty when nothing offends or the target predates PHP 8.0 (mixed did not exist before then)
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // User view: choose the findings list branch for this case.
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.0)) {
            // The explicit mixed type arrived in PHP 8.0, so stay silent on targets that predate it.
            return [];
        }

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOfAny($analysisUnit, [Stmt\ClassMethod::class, Stmt\Function_::class]) as $functionLike) {
            // User view: choose the findings list branch for this case.
            if ($functionLike instanceof Stmt\ClassMethod && !$functionLike->isPublic()) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (!$functionLike instanceof Stmt\ClassMethod && !$functionLike instanceof Stmt\Function_) {
                continue;
            }

            $locations = $this->mixedLocations($functionLike);
            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
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
                                 'locations'   => $locations,
                             ],
            );
        }

        return $findings;
    }

    /**
     * List source locations where broad mixed types appear.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\ClassMethod|Stmt\Function_ $functionLike - Function-like whose parameter and return types are scanned
     *                                                      for mixed; the labels feed the finding message verbatim.
     *
     * @return list<string> - human-readable labels for each mixed site ($param names and 'return type'), in source order; empty when none use mixed
     */
    private function mixedLocations(Stmt\ClassMethod|Stmt\Function_ $functionLike): array
    {
        $locations = [];

        // User view: add each item that can appear in findings list.
        foreach ($functionLike->params as $parameter) {
            // User view: choose the findings list branch for this case.
            if (ModernisationNodeHelper::typeName($parameter->type) === 'mixed') {
                $name        = $parameter->var instanceof \PhpParser\Node\Expr\Variable && is_string($parameter->var->name)
                    ? '$' . $parameter->var->name
                    : 'parameter';
                $locations[] = $name;
            }
        }

        // User view: choose the findings list branch for this case.
        if (ModernisationNodeHelper::typeName($functionLike->returnType) === 'mixed') {
            $locations[] = 'return type';
        }

        return $locations;
    }
}
