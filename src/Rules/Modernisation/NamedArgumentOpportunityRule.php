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
 * Detects calls whose repeated scalar arguments would be clearer as named arguments.
 */
final readonly class NamedArgumentOpportunityRule implements RuleInterface
{
    /**
     * Stable rule identifier for named argument opportunity findings.
     */
    public const ID = 'modernisation.named-argument-opportunity';

    /**
     * Describe the named argument opportunity rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
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
     * Find calls with many positional arguments that would read better named.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for named argument opportunities.
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
        foreach ($calls as $call) {
            /** @var Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call NodeIndex query restricts these classes. */
            if ($this->isVariadicCall($call, $variadicCallableNames)) {
                continue;
            }

            $reason = $this->reason($call->args, $minPositionalArguments);
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
     * Decide whether a call carries enough positional arguments to recommend named arguments.
     *
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args - Raw call arguments; spreads and named
     *   arguments are present but do not count toward the positional total.
     * @param int $minPositionalArguments - Inclusive lower bound below which the call is left alone.
     *
     * @return string|null - Explanation when the call should use named arguments.
     */
    private function reason(array $args, int $minPositionalArguments): ?string
    {
        $positionalCount = 0;
        $previousType    = null;
        $hasAdjacentAmbiguity = false;
        $hasBooleanOrNull = false;

        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->name !== null) {
                continue;
            }

            $positionalCount++;
            $type = $this->argumentClarityType($arg->value);
            if ($type === 'bool' || $type === 'null') {
                $hasBooleanOrNull = true;
            }

            if ($type !== null && $type === $previousType) {
                $hasAdjacentAmbiguity = true;
            }

            $previousType = $type;

        }

        if ($positionalCount >= $minPositionalArguments) {
            return sprintf('%d positional arguments', $positionalCount);
        }

        if ($positionalCount >= 4 && $hasAdjacentAmbiguity) {
            return sprintf('%d positional arguments with adjacent same-type scalar values', $positionalCount);
        }

        if ($positionalCount >= 4 && $hasBooleanOrNull) {
            return sprintf('%d positional arguments including boolean/null flags', $positionalCount);
        }

        return null;
    }

    /**
     * Classify argument expressions that are easy to swap accidentally.
     *
     * @param Expr $expr - Positional argument expression.
     *
     * @return string|null - Ambiguity type, or null when the expression is self-describing enough.
     */
    private function argumentClarityType(Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return 'string';
        }

        if ($expr instanceof Node\Scalar\Int_) {
            return 'int';
        }

        if ($expr instanceof Node\Scalar\Float_) {
            return 'float';
        }

        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());
            if ($name === 'true' || $name === 'false') {
                return 'bool';
            }

            if ($name === 'null') {
                return 'null';
            }
        }

        return null;
    }

    /**
     * Find function and method names declared with variadic parameters in the same file.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose own function-like declarations are scanned for variadic params.
     *
     * @return array<string, true> - Lowercase variadic function and method names.
     */
    private function variadicCallableNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        foreach (NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]) as $functionLike) {
            if (!$functionLike instanceof ClassMethod && !$functionLike instanceof Function_) {
                continue;
            }

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
     * Detect calls to same-file variadic functions or methods, where named arguments would be misleading.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call whose callee name is matched against the variadic set.
     * @param array<string, true> $variadicNames - Lowercase callable names declared with variadic params.
     *
     * @return bool - True when the call target is variadic.
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
     * Extract the simple callee name from a supported call expression.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call whose callee name is needed.
     *
     * @return string|null - Simple callee name, or null when the callee is dynamic.
     */
    private function callableSimpleName(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        if ($call instanceof Expr\FuncCall) {
            return $call->name instanceof Node\Name ? $call->name->getLast() : null;
        }

        return $call->name instanceof Node\Identifier ? $call->name->toString() : null;
    }
}
