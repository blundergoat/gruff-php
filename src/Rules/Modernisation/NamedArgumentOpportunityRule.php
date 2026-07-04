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
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a call passing a long run of positional scalar arguments, where PHP 8 named arguments would make
 * each value's role obvious at the call site, so the user can consider naming them.
 *
 * Runs per file on PHP 8.0+ targets. It fires when a call has at least the configured number of positional
 * arguments, or slightly fewer when adjacent same-type values or bare boolean/null flags make a mix-up
 * easy. Calls to same-file variadic callables are skipped, since naming there misleads. Low confidence and
 * advisory - names are only worth adding for stable APIs, so gruff-php reports only.
 */
final readonly class NamedArgumentOpportunityRule implements RuleInterface
{
    /**
     * Stable rule identifier for named argument opportunity findings.
     */
    public const ID = 'modernisation.named-argument-opportunity';

    /**
     * Describes the named-argument-opportunity rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults, including the minimum positional-argument threshold.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Named argument opportunity',
            pillar:            Pillar::Modernisation,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Advisory,
            confidence:        Confidence::Low,
            defaultThresholds: ['minPositionalArguments' => 5],
        );
    }

    /**
     * Reports each call whose many positional arguments would read better as named arguments.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context supplying the target PHP version and threshold.
     *
     * @return list<Finding> - One finding per named-argument opportunity; empty on pre-8.0 targets or when no call qualifies.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.0)) {
            // Named arguments are a PHP 8.0 feature; below that target the suggestion would be unactionable.
            return [];
        }

        $definition             = $this->definition();
        $minPositionalArguments = (int) $ruleContext->settingsFor($definition)->numericThreshold('minPositionalArguments');
        $variadicCallableNames  = $this->variadicCallableNames($analysisUnit);
        $findings               = [];

        $calls = NodeIndex::nodesOfAny($analysisUnit, [Expr\FuncCall::class, Expr\MethodCall::class, Expr\StaticCall::class]);
        // Weigh every function, method, and static call in the file.
        foreach ($calls as $call) {
            /** @var Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call NodeIndex query restricts these classes. */
            // A variadic target has no fixed parameter names to suggest, so naming there would mislead.
            if ($this->isVariadicCall($call, $variadicCallableNames)) {
                continue;
            }

            $reason = $this->reason($call->args, $minPositionalArguments);
            // Nothing about this call's arguments warrants the suggestion.
            if ($reason === null) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('Call with %s may be clearer with PHP 8 named arguments.', $reason),
                filePath:    $analysisUnit->file->displayPath,
                line:        $call->getStartLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::Modernisation,
                tier:        RuleTier::V01,
                confidence:  Confidence::Low,
                remediation: 'Consider named arguments only for stable APIs where parameter names are part of the intended contract; gruff-php reports only.',
                metadata:    [
                    'requiresPhp' => 8.0,
                    'reason' => $reason,
                ],
            );
        }

        return $findings;
    }

    /**
     * Decides whether a call carries enough positional arguments to recommend named arguments, and why.
     *
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args - Raw call arguments; spreads and named
     *   arguments are present but do not count toward the positional total.
     * @param int $minPositionalArguments - Inclusive lower bound below which the call is left alone.
     *
     * @return string|null - Explanation when the call should use named arguments; null when it reads clearly enough.
     */
    private function reason(array $args, int $minPositionalArguments): ?string
    {
        $positionalCount = 0;
        $previousType    = null;
        $hasAdjacentAmbiguity = false;
        $hasBooleanOrNull = false;

        // Count and classify each positional argument.
        foreach ($args as $arg) {
            // A spread or already-named argument does not count toward the positional total.
            if (!$arg instanceof Node\Arg || $arg->name !== null) {
                continue;
            }

            $positionalCount++;
            $type = $this->argumentClarityType($arg->value);
            // A bare boolean or null flag is especially easy to pass into the wrong slot.
            if ($type === 'bool' || $type === 'null') {
                $hasBooleanOrNull = true;
            }

            // Two adjacent same-type scalar values are easy to transpose by mistake.
            if ($type !== null && $type === $previousType) {
                $hasAdjacentAmbiguity = true;
            }

            $previousType = $type;

        }

        // Enough positional arguments on their own to recommend naming.
        if ($positionalCount >= $minPositionalArguments) {
            return sprintf('%d positional arguments', $positionalCount);
        }

        // Ambiguous calls are flagged one argument earlier than plain calls, but never below 4 (where
        // adjacency/flag confusion begins) and never below a raised minPositionalArguments floor, so
        // raising the threshold to suppress shorter calls also suppresses the ambiguity paths.
        $ambiguityFloor = max(4, $minPositionalArguments - 1);

        // Just under the plain floor, but adjacent same-type values tip it over.
        if ($positionalCount >= $ambiguityFloor && $hasAdjacentAmbiguity) {
            return sprintf('%d positional arguments with adjacent same-type scalar values', $positionalCount);
        }

        // Just under the plain floor, but bare boolean/null flags tip it over.
        if ($positionalCount >= $ambiguityFloor && $hasBooleanOrNull) {
            return sprintf('%d positional arguments including boolean/null flags', $positionalCount);
        }

        return null;
    }

    /**
     * Classifies an argument expression by the scalar type that makes it easy to swap by mistake.
     *
     * @param Expr $expr - Positional argument expression.
     *
     * @return string|null - Ambiguity type, or null when the expression is self-describing enough to leave alone.
     */
    private function argumentClarityType(Expr $expr): ?string
    {
        // A bare string literal carries no hint of which parameter it fills.
        if ($expr instanceof Node\Scalar\String_) {
            return 'string';
        }

        // A bare integer literal is equally anonymous.
        if ($expr instanceof Node\Scalar\Int_) {
            return 'int';
        }

        // So is a bare float literal.
        if ($expr instanceof Node\Scalar\Float_) {
            return 'float';
        }

        // A `true`/`false`/`null` constant reads as an opaque flag at the call site.
        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());
            // Boolean flags are the classic swap hazard.
            if ($name === 'true' || $name === 'false') {
                return 'bool';
            }

            // A null flag is just as ambiguous.
            if ($name === 'null') {
                return 'null';
            }
        }

        return null;
    }

    /**
     * Collects the names of same-file functions and methods declared with a variadic parameter.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose own function-like declarations are scanned for variadic params.
     *
     * @return array<string, true> - Lowercase variadic function and method names.
     */
    private function variadicCallableNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        // Scan every function and method declared in the file.
        foreach (NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]) as $functionLike) {
            // Guard the type so only real callables are inspected.
            if (!$functionLike instanceof ClassMethod && !$functionLike instanceof Function_) {
                continue;
            }

            // A single variadic parameter marks the whole callable as variadic.
            foreach ($functionLike->params as $param) {
                if ($param->variadic) {
                    $names[strtolower($functionLike->name->toString())] = true;
                    break;
                }
            }
        }

        // The set of same-file variadic callable names, used later to suppress misleading suggestions on them.
        return $names;
    }

    /**
     * Reports whether a call targets a same-file variadic callable, where named arguments would mislead.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call whose callee name is matched against the variadic set.
     * @param array<string, true> $variadicNames - Lowercase callable names declared with variadic params.
     *
     * @return bool - True when the call target is variadic, false otherwise.
     */
    private function isVariadicCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, array $variadicNames): bool
    {
        $name = $this->callableSimpleName($call);
        if ($name === null) {
            // A dynamic or expression callee cannot be resolved to a declaration, so it cannot be matched as variadic.
            return false;
        }

        // True only when this exact callable name was recorded as variadic earlier in the same file.
        return isset($variadicNames[strtolower($name)]);
    }

    /**
     * Extracts the simple callee name from a supported call expression.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call whose callee name is needed.
     *
     * @return string|null - Simple callee name, or null when the callee is dynamic and has no static name.
     */
    private function callableSimpleName(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        // A function call's name is a Name node; a method or static call's is an Identifier.
        if ($call instanceof Expr\FuncCall) {
            return $call->name instanceof Node\Name ? $call->name->getLast() : null;
        }

        return $call->name instanceof Node\Identifier ? $call->name->toString() : null;
    }
}
