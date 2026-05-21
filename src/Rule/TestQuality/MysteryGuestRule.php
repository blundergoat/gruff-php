<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

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
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * Detects tests that reach hidden external fixtures or global state.
 */
final readonly class MysteryGuestRule implements RuleInterface
{
    /**
     * Stable rule identifier for mystery guest findings.
     */
    public const ID = 'test-quality.mystery-guest';

    /**
     * File/database reads that can hide external fixtures.
     */
    private const READ_FUNCTIONS = [
        'file_get_contents',
        'file_exists',
        'fopen',
        'file',
        'is_file',
        'parse_ini_file',
        'mysqli_connect',
    ];

    /**
     * File writes that make a later read explicit test-owned setup.
     */
    private const WRITE_FUNCTION_TARGET_ARG = [
        'file_put_contents' => 0,
        'mkdir' => 0,
        'copy' => 1,
    ];

    /**
     * Describe the mystery guest test rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Mystery guest',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find tests that reach external files or databases from inside the test body.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for hidden external test dependencies.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings   = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\FuncCall::class, Expr\New_::class]) as $node) {
                $guest = $this->mysteryGuest($node);
                if ($guest === null) {
                    continue;
                }

                if ($this->usesPreparedPath($scope, $node)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s reaches out to %s from inside the test body.', $scope->symbol, $guest),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $node->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Medium,
                    symbol:      $scope->symbol,
                    remediation: 'Make external files or database fixtures explicit in setup, or replace them with inline test data.',
                    metadata:    ['guest' => $guest],
                );
            }
        }

        return $findings;
    }

    /**
     * Identify external file/database access in a call or constructor node.
     *
     * @return string|null Guest dependency name, or null when none is detected.
     */
    private function mysteryGuest(Node $node): ?string
    {
        if ($node instanceof Expr\FuncCall) {
            $name = TestQualityNodeHelper::functionName($node);

            return in_array($name, self::READ_FUNCTIONS, true)
                ? (string) $name
                : null;
        }

        if ($node instanceof Expr\New_ && $node->class instanceof Name) {
            $class = strtolower($node->class->toString());

            return in_array($class, ['pdo', 'mysqli'], true) ? $node->class->toString() : null;
        }

        return null;
    }

    /**
     * Detect reads from paths the test created or handed to the SUT earlier in the same test.
     *
     * @return bool True when the file access is test-owned rather than a hidden fixture.
     */
    private function usesPreparedPath(TestQualityScope $scope, Node $node): bool
    {
        $path = $this->readPathExpression($node);
        if (!$path instanceof Expr) {
            return false;
        }

        $pathKeys = $this->pathKeys($path);
        if ($pathKeys === []) {
            return false;
        }

        $readerLine = $node->getStartLine();
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\FuncCall::class, Expr\MethodCall::class, Expr\StaticCall::class, Expr\New_::class]) as $candidate) {
            if ($candidate->getStartLine() >= $readerLine) {
                continue;
            }

            $preparedKeys = $this->preparedPathKeys($candidate);
            if (array_intersect($pathKeys, $preparedKeys) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the path argument from a file-read call.
     *
     * @return Expr|null Path expression, or null for non-path guests.
     */
    private function readPathExpression(Node $node): ?Expr
    {
        if (!$node instanceof Expr\FuncCall) {
            return null;
        }

        $name = TestQualityNodeHelper::functionName($node);
        if ($name === null || !in_array($name, self::READ_FUNCTIONS, true) || $name === 'mysqli_connect') {
            return null;
        }

        $arg = $node->args[0] ?? null;

        return $arg instanceof Arg ? $arg->value : null;
    }

    /**
     * Collect path-identifying keys prepared by a prior setup/SUT call.
     *
     * @return list<string> Path keys.
     */
    private function preparedPathKeys(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall|Expr\New_ $node): array
    {
        if ($node instanceof Expr\FuncCall) {
            $name = TestQualityNodeHelper::functionName($node);
            if ($name !== null && isset(self::WRITE_FUNCTION_TARGET_ARG[$name])) {
                $arg = $node->args[self::WRITE_FUNCTION_TARGET_ARG[$name]] ?? null;

                return $arg instanceof Arg ? $this->pathKeys($arg->value) : [];
            }

            if ($name !== null && in_array($name, self::READ_FUNCTIONS, true)) {
                return [];
            }
        }

        if (($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)
            && (TestQualityNodeHelper::isAssertionCall($node) || TestQualityNodeHelper::isMockCreationCall($node) || TestQualityNodeHelper::isMockVerificationCall($node))) {
            return [];
        }

        return $this->argumentPathKeys(array_values($node->args));
    }

    /**
     * @param list<Arg|Node\VariadicPlaceholder> $args Call arguments to inspect.
     * @return list<string> Path keys found in arguments.
     */
    private function argumentPathKeys(array $args): array
    {
        $keys = [];

        foreach ($args as $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }

            array_push($keys, ...$this->pathKeys($arg->value));
        }

        return array_values(array_unique($keys));
    }

    /**
     * Build stable keys for simple literal, variable, concatenated, and array path expressions.
     *
     * @return list<string> Keys such as `var:outputPath` or `literal:/tmp/file.json`.
     */
    private function pathKeys(Expr $expression): array
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return ['var:' . $expression->name];
        }

        if ($expression instanceof Scalar\String_) {
            return ['literal:' . $expression->value];
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            return array_values(array_unique([
                ...$this->pathKeys($expression->left),
                ...$this->pathKeys($expression->right),
            ]));
        }

        if ($expression instanceof Expr\Array_) {
            $keys = [];
            foreach ($expression->items as $arrayItem) {
                array_push($keys, ...$this->pathKeys($arrayItem->value));
            }

            return array_values(array_unique($keys));
        }

        return [];
    }
}
