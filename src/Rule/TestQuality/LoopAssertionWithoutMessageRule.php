<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final readonly class LoopAssertionWithoutMessageRule implements RuleInterface
{
    public const ID = 'test-quality.loop-assertion-without-message';

    /**
     * Describe the loop assertion message rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Assertion in loop without message',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    /**
     * Find assertions inside loops that lack a context-bearing message.
     *
     * @return list<Finding> Findings for loop assertions without messages.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            $loops = $finder->find(
                $scope->statements,
                static fn (Node $node): bool => $node instanceof Stmt\For_
                    || $node instanceof Stmt\Foreach_
                    || $node instanceof Stmt\While_
                    || $node instanceof Stmt\Do_,
            );

            foreach ($loops as $loop) {
                if (!$loop instanceof Stmt\For_
                    && !$loop instanceof Stmt\Foreach_
                    && !$loop instanceof Stmt\While_
                    && !$loop instanceof Stmt\Do_
                ) {
                    continue;
                }

                $assertions = $finder->find(
                    $loop->stmts,
                    static fn (Node $node): bool => ($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)
                        && TestQualityNodeHelper::isAssertionCall($node),
                );

                foreach ($assertions as $assertion) {
                    if (!$assertion instanceof Expr\FuncCall && !$assertion instanceof Expr\MethodCall && !$assertion instanceof Expr\StaticCall) {
                        continue;
                    }

                    if ($this->hasMessageArgument($assertion)) {
                        continue;
                    }

                    $name = TestQualityNodeHelper::callName($assertion) ?? 'assertion';

                    $findings[] = new Finding(
                        ruleId: self::ID,
                        message: sprintf(
                            '%s contains a %s() inside a loop without a context-bearing message.',
                            $scope->symbol,
                            $name,
                        ),
                        filePath: $unit->file->displayPath,
                        line: $assertion->getStartLine(),
                        severity: Severity::Advisory,
                        pillar: Pillar::TestQuality,
                        tier: RuleTier::V01,
                        confidence: Confidence::Medium,
                        symbol: $scope->symbol,
                        remediation: 'Pass a message argument that names the iteration (e.g. "row $i") so failures point at the offending row.',
                        metadata: ['assertion' => $name],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Check whether an assertion call appears to include a message argument.
     *
     * @return bool True when the final argument looks like message text.
     */
    private function hasMessageArgument(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        if ($call->args === []) {
            return false;
        }

        $lastIndex = count($call->args) - 1;
        $lastArg = $call->args[$lastIndex] ?? null;
        if (!$lastArg instanceof Arg) {
            return false;
        }

        return $this->isLikelyStringExpression($lastArg->value);
    }

    /**
     * Detect string-like expressions commonly used for assertion messages.
     *
     * @return bool True when the expression can produce message text.
     */
    private function isLikelyStringExpression(Expr $expr): bool
    {
        if ($expr instanceof Scalar\String_) {
            return true;
        }

        if ($expr instanceof Scalar\InterpolatedString) {
            return true;
        }

        if ($expr instanceof Expr\BinaryOp\Concat) {
            return true;
        }

        if ($expr instanceof Expr\FuncCall) {
            $name = TestQualityNodeHelper::functionName($expr);

            return $name !== null && in_array($name, ['sprintf', 'vsprintf', 'printf', 'format'], true);
        }

        return false;
    }
}
