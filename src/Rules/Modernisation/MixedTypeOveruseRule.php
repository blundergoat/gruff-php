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
 * Flags a public signature that leans on the catch-all `mixed` type where a narrower parameter or return
 * type would document the real contract, so the user can tighten the API for callers and static analysis.
 *
 * Runs per file on PHP 8.0+ targets (mixed did not exist before then), over public methods and every
 * function. It reports each public function-like that declares `mixed` on a parameter or return, listing
 * the offending sites. Advisory only - narrowing is a suggestion to weigh, never a build-breaker.
 */
final readonly class MixedTypeOveruseRule implements RuleInterface
{
    /**
     * Stable rule identifier for mixed type overuse findings.
     */
    public const ID = 'modernisation.mixed-type-overuse';

    /**
     * Describes the mixed-type-overuse rule for the registry and reports.
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A public method implementing an interface that itself declares mixed, such as ArrayAccess::offsetGet or JsonSerializable::jsonSerialize.',
                    'mitigation' => 'Narrowing the native signature would break the contract, so keep mixed there and narrow through @param and @return PHPDoc instead.',
                ],
            ],
        );
    }

    /**
     * Reports each public function-like that overuses the explicit `mixed` type on a parameter or return.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context supplying the target PHP version.
     *
     * @return list<Finding> - one finding per public function-like that declares mixed on a parameter or
     *   return; empty when nothing offends or the target predates PHP 8.0 (mixed did not exist before then)
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.0)) {
            // The explicit mixed type arrived in PHP 8.0, so stay silent on targets that predate it.
            return [];
        }

        $findings = [];

        // Check every method and free function in the file.
        foreach (NodeIndex::nodesOfAny($analysisUnit, [Stmt\ClassMethod::class, Stmt\Function_::class]) as $functionLike) {
            // A non-public method is not part of the API contract this rule guards.
            if ($functionLike instanceof Stmt\ClassMethod && !$functionLike->isPublic()) {
                continue;
            }

            // Guard the type: only a method or function carries the params and return this rule scans.
            if (!$functionLike instanceof Stmt\ClassMethod && !$functionLike instanceof Stmt\Function_) {
                continue;
            }

            $locations = $this->mixedLocations($functionLike);
            // Nothing here uses mixed, so there is nothing to suggest narrowing.
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
     * Lists the source locations where a signature falls back to the broad `mixed` type.
     *
     * @param Stmt\ClassMethod|Stmt\Function_ $functionLike - Function-like whose parameter and return types are scanned
     *                                                      for mixed; the labels feed the finding message verbatim.
     *
     * @return list<string> - human-readable labels for each mixed site ($param names and 'return type'), in source order; empty when none use mixed
     */
    private function mixedLocations(Stmt\ClassMethod|Stmt\Function_ $functionLike): array
    {
        $locations = [];

        // Inspect each declared parameter for a mixed type.
        foreach ($functionLike->params as $parameter) {
            // A mixed parameter hides the shape the caller must actually pass.
            if (ModernisationNodeHelper::typeName($parameter->type) === 'mixed') {
                $name        = $parameter->var instanceof \PhpParser\Node\Expr\Variable && is_string($parameter->var->name)
                    ? '$' . $parameter->var->name
                    : 'parameter';
                $locations[] = $name;
            }
        }

        // A mixed return type leaves callers guessing what they get back.
        if (ModernisationNodeHelper::typeName($functionLike->returnType) === 'mixed') {
            $locations[] = 'return type';
        }

        return $locations;
    }
}
