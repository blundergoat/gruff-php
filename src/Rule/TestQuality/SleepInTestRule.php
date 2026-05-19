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
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * Detects tests that depend on sleeps or wall-clock reads.
 */
final readonly class SleepInTestRule implements RuleInterface
{
    /**
     * Stable identifier for the sleep-in-test rule.
     */
    public const ID = 'test-quality.sleep-in-test';

    /**
     * Functions that pause execution and slow test feedback.
     */
    private const SLEEP_FUNCTIONS = ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'];

    /**
     * Functions that read global wall-clock state in tests.
     */
    private const WALL_CLOCK_FUNCTIONS = ['time', 'microtime'];

    /**
     * Date/time classes that bind tests to the current wall clock.
     */
    private const WALL_CLOCK_DATETIME_CLASSES = ['datetime', 'datetimeimmutable'];

    /**
     * Describe the rule for the registry and reports.
     *
     * @return RuleDefinition
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Sleep or wall-clock read in test',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Flag tests that sleep or read the wall clock; both make tests flaky and slow.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding>
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings   = [];
        $nodeFinder = new NodeFinder();

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            array_push(
                $findings,
                ...$this->functionFindings($analysisUnit, $scope),
                ...$this->dateTimeFindings($nodeFinder, $analysisUnit, $scope),
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function functionFindings(AnalysisUnit $analysisUnit, TestQualityScope $scope): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if (!$call instanceof Expr\FuncCall) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            if ($name === null) {
                continue;
            }

            $finding = $this->functionFinding($analysisUnit, $scope, $call, $name);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Build a Finding for a sleep or wall-clock function call, or null when the call is neither.
     *
     * @return Finding|null
     */
    private function functionFinding(AnalysisUnit $analysisUnit, TestQualityScope $scope, Expr\FuncCall $call, string $name): ?Finding
    {
        if (in_array($name, self::SLEEP_FUNCTIONS, true)) {
            return $this->sleepFinding($analysisUnit, $scope, $call, $name);
        }

        if (in_array($name, self::WALL_CLOCK_FUNCTIONS, true)) {
            return $this->wallClockFunctionFinding($analysisUnit, $scope, $call, $name);
        }

        return null;
    }

    /**
     * @return list<Finding>
     */
    private function dateTimeFindings(NodeFinder $nodeFinder, AnalysisUnit $analysisUnit, TestQualityScope $scope): array
    {
        $findings = [];

        foreach ($nodeFinder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\New_) as $newExpression) {
            if ($newExpression instanceof Expr\New_ && $this->isWallClockDateTimeConstructor($newExpression)) {
                $findings[] = $this->dateTimeFinding($analysisUnit, $scope, $newExpression);
            }
        }

        return $findings;
    }

    /**
     * Detect whether a `new DateTime(...)` / `new DateTimeImmutable(...)` reads the current time.
     *
     * @return bool True when the class is a DateTime variant constructed with "now" or no argument.
     */
    private function isWallClockDateTimeConstructor(Expr\New_ $newExpression): bool
    {
        if (!$newExpression->class instanceof Name) {
            return false;
        }

        $className = strtolower($newExpression->class->getLast());

        return in_array($className, self::WALL_CLOCK_DATETIME_CLASSES, true)
            && $this->isWallClockDateTime($newExpression);
    }

    /**
     * Detect whether the DateTime constructor argument is empty or the literal string "now".
     *
     * @return bool
     */
    private function isWallClockDateTime(Expr\New_ $newExpression): bool
    {
        if ($newExpression->args === []) {
            return true;
        }

        $first = $newExpression->args[0];
        if (!$first instanceof Arg) {
            return false;
        }

        $literalValue = TestQualityNodeHelper::literalValue($first->value);

        return is_string($literalValue) && strtolower($literalValue) === 'now';
    }

    /**
     * Build the Finding for a sleep / usleep / time_nanosleep call inside a test.
     *
     * @return Finding
     */
    private function sleepFinding(AnalysisUnit $analysisUnit, TestQualityScope $scope, Expr\FuncCall $call, string $name): Finding
    {
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('%s sleeps during the test run, which is a flakiness and latency smell.', $scope->symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $call->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::TestQuality,
            tier:        RuleTier::V01,
            confidence:  Confidence::High,
            symbol:      $scope->symbol,
            remediation: 'Replace sleeps with explicit clocks, retries with deadlines, or observable synchronization points.',
            metadata:    ['variant' => 'sleep', 'function' => $name],
        );
    }

    /**
     * Build the Finding for a time() / microtime() call inside a test.
     *
     * @return Finding
     */
    private function wallClockFunctionFinding(AnalysisUnit $analysisUnit, TestQualityScope $scope, Expr\FuncCall $call, string $name): Finding
    {
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('%s reads the wall clock via %s(), which couples the test to real time.', $scope->symbol, $name),
            filePath:    $analysisUnit->file->displayPath,
            line:        $call->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::TestQuality,
            tier:        RuleTier::V01,
            confidence:  Confidence::High,
            symbol:      $scope->symbol,
            remediation: 'Inject a fake clock or fixed timestamp instead of calling time()/microtime() directly.',
            metadata:    ['variant' => 'wall-clock', 'function' => $name],
        );
    }

    /**
     * Build the Finding for a `new DateTime("now")` / `new DateTimeImmutable()` inside a test.
     *
     * @return Finding
     */
    private function dateTimeFinding(AnalysisUnit $analysisUnit, TestQualityScope $scope, Expr\New_ $newExpression): Finding
    {
        if (!$newExpression->class instanceof Name) {
            throw new \LogicException('DateTime finding requires a named class.');
        }

        $className = $newExpression->class;

        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('%s constructs %s with the current time, which couples the test to real time.', $scope->symbol, $className->toString()),
            filePath:    $analysisUnit->file->displayPath,
            line:        $newExpression->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::TestQuality,
            tier:        RuleTier::V01,
            confidence:  Confidence::High,
            symbol:      $scope->symbol,
            remediation: 'Pass a fixed timestamp to the DateTime constructor or inject a fake clock.',
            metadata:    ['variant' => 'datetime', 'class' => $className->toString()],
        );
    }
}
