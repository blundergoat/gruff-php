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
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Flags an old-style `[$object, 'method']` array callable that PHP 8.1's first-class callable syntax
 * (`$object->method(...)`) can express more clearly, so the user can consider modernising it.
 *
 * Runs per file on PHP 8.1+ targets. It reports each two-element callable array that sits where a callable
 * is actually used (an argument, assignment, or return). Advisory only - the rewrite can shift binding
 * semantics, so gruff-php suggests rather than gates.
 */
final readonly class FirstClassCallableCandidateRule implements RuleInterface
{
    /**
     * Stable rule identifier for first-class callable candidate findings.
     */
    public const ID = 'modernisation.first-class-callable-candidate';

    /**
     * Describes the first-class-callable-candidate rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, medium confidence).
     */
    public function definition(): RuleDefinition
    {
        // Advisory at medium confidence: rewriting to first-class callables can shift binding, so it only suggests.
        return new RuleDefinition(
            id:              self::ID,
            name:            'First-class callable candidate',
            pillar:          Pillar::Modernisation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Reports each array callable that PHP 8.1 first-class callable syntax could replace.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context supplying the target PHP version.
     *
     * @return list<Finding> - One finding per first-class callable candidate; empty on pre-8.1 targets or when none qualify.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.1)) {
            // First-class callable syntax needs PHP 8.1, so stay silent on targets that cannot use it.
            return [];
        }

        $findings = [];

        // Scan every array literal in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Array_::class) as $array) {
            // Only a genuine callable pair used in a callable position is worth suggesting.
            if (!$this->isCallableArray($array) || !$this->isCallableContext($array)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Array callable syntax may be replaceable with PHP 8.1 first-class callable syntax.',
                filePath:    $analysisUnit->file->displayPath,
                line:        $array->getStartLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::Modernisation,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Consider first-class callable syntax only when callable binding semantics remain equivalent; gruff-php reports only.',
                metadata:    [
                    'requiresPhp' => 8.1,
                ],
            );
        }

        return $findings;
    }

    /**
     * Reports whether an array has the two-part `[target, 'method']` callable shape.
     *
     * @param Expr\Array_ $array - Array literal under inspection; only the unkeyed [target, 'method'] pair matches.
     *
     * @return bool - True when the array looks like a callable pair, false otherwise.
     */
    private function isCallableArray(Expr\Array_ $array): bool
    {
        if (count($array->items) !== 2) {
            // A callable pair has exactly two elements, so anything else is not this shape.
            return false;
        }

        if ($array->items[0]->key !== null || $array->items[1]->key !== null) {
            // Explicit keys mean a data map, not a positional callable pair.
            return false;
        }

        $target = $array->items[0]->value;
        $method = $array->items[1]->value;

        if (!$method instanceof Scalar\String_) {
            // A dynamic method element cannot be proven callable statically, so do not flag it.
            return false;
        }

        if ($target instanceof Expr\ClassConstFetch) {
            // A class-constant target only qualifies as the ::class form of a static callable.
            return $target->name instanceof Node\Identifier
                && strtolower($target->name->toString()) === 'class';
        }

        // A variable or property target is the instance form of an array callable.
        return $target instanceof Expr\Variable
            || $target instanceof Expr\PropertyFetch;
    }

    /**
     * Reports whether the array callable sits where a callable would actually be invoked.
     *
     * @param Expr\Array_ $array - Array literal whose enclosing node decides if a callable would actually be invoked.
     *
     * @return bool - True when the parent context can accept a callable, false when it is plain array data.
     */
    private function isCallableContext(Expr\Array_ $array): bool
    {
        $parent = ModernisationNodeHelper::parent($array);

        // Only an argument, assignment, or return treats the pair as a callable rather than plain array data.
        return $parent instanceof Node\Arg
            || $parent instanceof Expr\Assign
            || $parent instanceof Stmt\Return_;
    }
}
