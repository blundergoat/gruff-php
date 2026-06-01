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
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassMethod;

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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Hand back this rule's fixed identity and defaults for the registry and reports.
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for named argument opportunities.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.0)) {
            // Named arguments are a PHP 8.0 feature; below that target the suggestion would be unactionable.
            return [];
        }

        $definition             = $this->definition();
        $minPositionalArguments = (int) $ruleContext->settingsFor($definition)->numericThreshold('minPositionalArguments');
        $variadicMethodNames    = $this->variadicMethodNames($analysisUnit);
        $findings               = [];

        $calls = NodeIndex::nodesOfAny($analysisUnit, [Expr\MethodCall::class, Expr\StaticCall::class]);
        foreach ($calls as $call) {
            /** @var Expr\MethodCall|Expr\StaticCall $call NodeIndex query restricts these classes. */
            if ($this->isVariadicMethodCall($call, $variadicMethodNames)) {
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

        // Hand back every named-argument opportunity gathered across the unit's calls.
        return $findings;
    }

    /**
     * Decide whether a call carries enough positional arguments to recommend named arguments.
     *
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args Raw call arguments; spreads and named
     *   arguments are present but do not count toward the positional total.
     * @param int $minPositionalArguments Inclusive lower bound below which the call is left alone.
     * @return string|null Explanation when the call should use named arguments.
     */
    private function reason(array $args, int $minPositionalArguments): ?string
    {
        $positionalCount = 0;

        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->name !== null) {
                continue;
            }

            $positionalCount++;

        }

        // Below the threshold the call stays silent (null); at or above it the count becomes the finding message.
        return $positionalCount >= $minPositionalArguments ? sprintf('%d positional arguments', $positionalCount) : null;
    }

    /**
     * Find method names declared with variadic parameters in the same file.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit whose own method declarations are scanned for variadic params.
     * @return array<string, true> Lowercase variadic method names.
     */
    private function variadicMethodNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        foreach (NodeIndex::nodesOf($analysisUnit, ClassMethod::class) as $classMethod) {
            foreach ($classMethod->params as $param) {
                if ($param->variadic) {
                    $names[strtolower($classMethod->name->toString())] = true;
                    break;
                }
            }
        }

        // The set of same-file variadic method names, used later to suppress misleading suggestions on them.
        return $names;
    }

    /**
     * Detect calls to same-file variadic methods, where named arguments would be misleading.
     *
     * @param Expr\MethodCall|Expr\StaticCall $call Call whose method name is matched against the variadic set.
     * @param array<string, true> $variadicMethodNames Lowercase method names declared with variadic params.
     * @return bool True when the call target is variadic.
     */
    private function isVariadicMethodCall(Expr\MethodCall|Expr\StaticCall $call, array $variadicMethodNames): bool
    {
        if (!$call->name instanceof Node\Identifier) {
            // A dynamic method name cannot be resolved to a declaration, so it cannot be matched as variadic.
            return false;
        }

        // True only when this exact method name was recorded as variadic earlier in the same file.
        return isset($variadicMethodNames[strtolower($call->name->toString())]);
    }
}
