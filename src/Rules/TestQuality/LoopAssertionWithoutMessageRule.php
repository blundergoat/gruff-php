<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Detects assertions inside loops that lack a failing-case message.
 */
final readonly class LoopAssertionWithoutMessageRule implements RuleInterface
{
    /**
     * Stable rule identifier for loop assertion message findings.
     */
    public const ID = 'test-quality.loop-assertion-without-message';

    /**
     * Describe the loop assertion message rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Assertion in loop without message',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find assertions inside loops that lack a context-bearing message.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for loop assertions without messages.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $nodeFinder = new NodeFinder();
        $findings   = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $loops = $nodeFinder->find(
                $scope->statements,
                static fn (Node $node): bool => $node instanceof Stmt\For_
                    || $node instanceof Stmt\Foreach_
                    || $node instanceof Stmt\While_
                    || $node instanceof Stmt\Do_,
            );

            // User view: add each item that can appear in findings list.
            foreach ($loops as $loop) {
                // User view: choose the findings list branch for this case.
                if (!$loop instanceof Stmt\For_
                    && !$loop instanceof Stmt\Foreach_
                    && !$loop instanceof Stmt\While_
                    && !$loop instanceof Stmt\Do_
                ) {
                    continue;
                }

                $assertions = $nodeFinder->find(
                    $loop->stmts,
                    static fn (Node $node): bool => ($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)
                        && TestQualityNodeHelper::isAssertionCall($node),
                );

                // User view: add each item that can appear in findings list.
                foreach ($assertions as $assertion) {
                    // User view: choose the findings list branch for this case.
                    if (!$assertion instanceof Expr\FuncCall && !$assertion instanceof Expr\MethodCall && !$assertion instanceof Expr\StaticCall) {
                        continue;
                    }

                    // User view: choose the findings list branch for this case.
                    if ($this->hasMessageArgument($assertion)) {
                        continue;
                    }

                    // User view: missing data becomes a safe findings list default.
                    $name = TestQualityNodeHelper::callName($assertion) ?? 'assertion';

                    $findings[] = new Finding(
                        ruleId:  self::ID,
                        message: sprintf(
                            '%s contains a %s() inside a loop without a context-bearing message.',
                            $scope->symbol,
                            $name,
                        ),
                        filePath:    $analysisUnit->file->displayPath,
                        line:        $assertion->getStartLine(),
                        severity:    Severity::Advisory,
                        pillar:      Pillar::TestQuality,
                        tier:        RuleTier::V01,
                        confidence:  Confidence::Medium,
                        symbol:      $scope->symbol,
                        remediation: 'Pass a message argument that names the iteration (e.g. "row $i") so failures point at the offending row.',
                        metadata:    ['assertion' => $name],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Check whether an assertion call appears to include a message argument.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Assertion call whose argument list is sniffed.
     *
     * @return bool - True when the final argument looks like message text.
     */
    private function hasMessageArgument(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = TestQualityNodeHelper::callName($call);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($name === null) {
            // No static name means no arity table to consult, so we report no detectable message argument.
            return false;
        }

        $minimumArgumentCount = $this->minimumArgumentCountBeforeMessage($name);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($minimumArgumentCount === null) {
            // Unrecognised assertion: fall back to sniffing the trailing argument for message-like text.
            return $this->hasLegacyStringMessageArgument($call);
        }

        return count($call->args) > $minimumArgumentCount;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $name - Lower-cased assertion name to classify against the known PHPUnit arities.
     *
     * @return int|null - Number of required non-message arguments, or null for unknown assertions.
     */
    private function minimumArgumentCountBeforeMessage(string $name): ?int
    {
        // User view: choose the findings list branch for this case.
        if (in_array($name, [
            'fail',
            'expectoutputstring',
            'expectoutputregex',
        ], true)) {
            // These take no required operand, so the very first argument already counts as the message.
            return 0;
        }

        // User view: choose the findings list branch for this case.
        if (in_array($name, [
            'assertarrayhaskey',
            'assertarraynothaskey',
            'assertcontains',
            'assertcontainsstring',
            'assertcount',
            'assertdirectoryexists',
            'assertdirectorydoesnotexist',
            'assertequals',
            'assertfileexists',
            'assertfiledoesnotexist',
            'assertgreaterthan',
            'assertgreaterthanorequal',
            'assertinstanceof',
            'assertlessthan',
            'assertlessthanorequal',
            'assertmatchesregularexpression',
            'assertnotsame',
            'assertsame',
            'assertstringcontainsstring',
            'assertstringendswith',
            'assertstringnotcontainsstring',
            'assertstringstartswith',
            'assertthat',
        ], true)) {
            // Two-operand assertions (expected + actual) place the optional message in the third slot.
            return 2;
        }

        // User view: choose the findings list branch for this case.
        if (str_starts_with($name, 'assert')) {
            // Default any other assert*() to a single operand before its optional message.
            return 1;
        }

        // Not a recognised assertion, so its arity is unknown and the caller must sniff instead.
        return null;
    }

    /**
     * Keep a conservative fallback for custom assertion helpers with unknown arity.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Custom assertion whose trailing argument is sniffed.
     *
     * @return bool - True when the final argument looks like message text.
     */
    private function hasLegacyStringMessageArgument(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        // User view: choose the findings list branch for this case.
        if (count($call->args) < 3) {
            // Below three arguments there is no room for the legacy expected/actual/message trailing slot.
            return false;
        }

        // User view: missing data becomes a safe findings list default.
        $lastArg = $call->args[count($call->args) - 1] ?? null;
        // User view: choose the findings list branch for this case.
        if (!$lastArg instanceof Arg) {
            // A spread or otherwise non-positional final argument cannot be read as a literal message.
            return false;
        }

        // Treat the call as carrying a message only when its trailing argument reads as message text.
        return $this->isLikelyStringExpression($lastArg->value);
    }

    /**
     * Detect string-like expressions commonly used for assertion messages.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr $expr - Trailing-argument expression tested for whether it can yield a readable message.
     *
     * @return bool - True when the expression can produce message text.
     */
    private function isLikelyStringExpression(Expr $expr): bool
    {
        // User view: choose the findings list branch for this case.
        if ($expr instanceof Scalar\String_) {
            // A plain string literal is the canonical assertion message.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if ($expr instanceof Scalar\InterpolatedString) {
            // An interpolated "row {$i}" string is exactly the per-iteration message this rule wants.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\BinaryOp\Concat) {
            // String concatenation builds a message, so accept it as message-bearing.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\FuncCall) {
            $name = TestQualityNodeHelper::functionName($expr);

            // Only the string-formatting builders count; other call results are not assumed to be a message.
            // User view: missing data becomes the expected findings list state.
            return $name !== null && in_array($name, ['sprintf', 'vsprintf', 'printf', 'format'], true);
        }

        // Any other expression (numbers, arrays, objects) cannot serve as message text.
        return false;
    }
}
