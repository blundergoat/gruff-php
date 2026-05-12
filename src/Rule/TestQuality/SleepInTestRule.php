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
            id: self::ID,
            name: 'Sleep or wall-clock read in test',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    /**
     * Flag tests that sleep or read the wall clock; both make tests flaky and slow.
     *
     * @return list<Finding>
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $findings = [];
        $finder = new NodeFinder();

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            array_push(
                $findings,
                ...$this->functionFindings($unit, $scope),
                ...$this->dateTimeFindings($finder, $unit, $scope),
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function functionFindings(AnalysisUnit $unit, TestQualityScope $scope): array
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

            $finding = $this->functionFinding($unit, $scope, $call, $name);
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
    private function functionFinding(AnalysisUnit $unit, TestQualityScope $scope, Expr\FuncCall $call, string $name): ?Finding
    {
        if (in_array($name, self::SLEEP_FUNCTIONS, true)) {
            return $this->sleepFinding($unit, $scope, $call, $name);
        }

        if (in_array($name, self::WALL_CLOCK_FUNCTIONS, true)) {
            return $this->wallClockFunctionFinding($unit, $scope, $call, $name);
        }

        return null;
    }

    /**
     * @return list<Finding>
     */
    private function dateTimeFindings(NodeFinder $finder, AnalysisUnit $unit, TestQualityScope $scope): array
    {
        $findings = [];

        foreach ($finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\New_) as $new) {
            if ($new instanceof Expr\New_ && $this->isWallClockDateTimeConstructor($new)) {
                $findings[] = $this->dateTimeFinding($unit, $scope, $new);
            }
        }

        return $findings;
    }

    /**
     * Detect whether a `new DateTime(...)` / `new DateTimeImmutable(...)` reads the current time.
     *
     * @return bool True when the class is a DateTime variant constructed with "now" or no argument.
     */
    private function isWallClockDateTimeConstructor(Expr\New_ $new): bool
    {
        if (!$new->class instanceof Name) {
            return false;
        }

        $className = strtolower($new->class->getLast());

        return in_array($className, self::WALL_CLOCK_DATETIME_CLASSES, true)
            && $this->isWallClockDateTime($new);
    }

    /**
     * Detect whether the DateTime constructor argument is empty or the literal string "now".
     *
     * @return bool
     */
    private function isWallClockDateTime(Expr\New_ $new): bool
    {
        if ($new->args === []) {
            return true;
        }

        $first = $new->args[0];
        if (!$first instanceof Arg) {
            return false;
        }

        $value = TestQualityNodeHelper::literalValue($first->value);

        return is_string($value) && strtolower($value) === 'now';
    }

    /**
     * Build the Finding for a sleep / usleep / time_nanosleep call inside a test.
     *
     * @return Finding
     */
    private function sleepFinding(AnalysisUnit $unit, TestQualityScope $scope, Expr\FuncCall $call, string $name): Finding
    {
        return new Finding(
            ruleId: self::ID,
            message: sprintf('%s sleeps during the test run, which is a flakiness and latency smell.', $scope->symbol),
            filePath: $unit->file->displayPath,
            line: $call->getStartLine(),
            severity: Severity::Warning,
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            confidence: Confidence::High,
            symbol: $scope->symbol,
            remediation: 'Replace sleeps with explicit clocks, retries with deadlines, or observable synchronization points.',
            metadata: ['variant' => 'sleep', 'function' => $name],
        );
    }

    /**
     * Build the Finding for a time() / microtime() call inside a test.
     *
     * @return Finding
     */
    private function wallClockFunctionFinding(AnalysisUnit $unit, TestQualityScope $scope, Expr\FuncCall $call, string $name): Finding
    {
        return new Finding(
            ruleId: self::ID,
            message: sprintf('%s reads the wall clock via %s(), which couples the test to real time.', $scope->symbol, $name),
            filePath: $unit->file->displayPath,
            line: $call->getStartLine(),
            severity: Severity::Warning,
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            confidence: Confidence::High,
            symbol: $scope->symbol,
            remediation: 'Inject a fake clock or fixed timestamp instead of calling time()/microtime() directly.',
            metadata: ['variant' => 'wall-clock', 'function' => $name],
        );
    }

    /**
     * Build the Finding for a `new DateTime("now")` / `new DateTimeImmutable()` inside a test.
     *
     * @return Finding
     */
    private function dateTimeFinding(AnalysisUnit $unit, TestQualityScope $scope, Expr\New_ $new): Finding
    {
        if (!$new->class instanceof Name) {
            throw new \LogicException('DateTime finding requires a named class.');
        }

        $className = $new->class;

        return new Finding(
            ruleId: self::ID,
            message: sprintf('%s constructs %s with the current time, which couples the test to real time.', $scope->symbol, $className->toString()),
            filePath: $unit->file->displayPath,
            line: $new->getStartLine(),
            severity: Severity::Warning,
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            confidence: Confidence::High,
            symbol: $scope->symbol,
            remediation: 'Pass a fixed timestamp to the DateTime constructor or inject a fake clock.',
            metadata: ['variant' => 'datetime', 'class' => $className->toString()],
        );
    }
}
