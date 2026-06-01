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
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;

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
     * @return RuleDefinition - static descriptor (id, name, pillar, tier, default severity, confidence) the registry uses to list and report this
     *                        rule
     */
    public function definition(): RuleDefinition
    {
        // Static registry descriptor: warning severity, high confidence, never varies per run.
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> - every sleep and wall-clock finding across all test scopes in this unit; empty when the unit has no tests or none offend
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            array_push(
                   $findings,
                ...$this->functionFindings($analysisUnit, $scope),
                ...$this->dateTimeFindings($analysisUnit, $scope),
            );
        }

        // Every sleep and wall-clock finding across all test scopes in this unit; empty when the unit has no tests.
        return $findings;
    }

    /**
     * Build function findings for the test-quality rule.
     *
     * @param AnalysisUnit     $analysisUnit Parsed unit supplying the display path stamped onto each finding.
     * @param TestQualityScope $scope        Single test method scope whose calls are searched for sleeps/clock reads.
     *
     * @return list<Finding> - one finding per sleep or wall-clock function call in this scope; empty when no call matches either set
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

        // One finding per sleep/clock call in this scope; calls that match neither set are skipped above.
        return $findings;
    }

    /**
     * Build a Finding for a sleep or wall-clock function call, or null when the call is neither.
     *
     * @param AnalysisUnit     $analysisUnit Parsed unit supplying the display path stamped onto the finding.
     * @param TestQualityScope $scope        Enclosing test scope, used for the symbol and message wording.
     * @param Expr\FuncCall    $call         The call expression under inspection; its start line anchors the finding.
     * @param string           $name         Lowercased called-function name already resolved from the call.
     *
     * @return Finding|null - the sleep- or wall-clock-variant finding for the call, or null when the call is neither family
     */
    private function functionFinding(AnalysisUnit $analysisUnit, TestQualityScope $scope, Expr\FuncCall $call, string $name): ?Finding
    {
        if (in_array($name, self::SLEEP_FUNCTIONS, true)) {
            // Sleep family: flake-and-latency variant of the finding.
            return $this->sleepFinding($analysisUnit, $scope, $call, $name);
        }

        if (in_array($name, self::WALL_CLOCK_FUNCTIONS, true)) {
            // time()/microtime(): real-time-coupling variant of the finding.
            return $this->wallClockFunctionFinding($analysisUnit, $scope, $call, $name);
        }

        // Call is neither sleep nor clock read, so it is harmless here and yields no finding.
        return null;
    }

    /**
     * Build date time findings for the test-quality rule.
     *
     * @param AnalysisUnit     $analysisUnit Parsed unit supplying the display path stamped onto each finding.
     * @param TestQualityScope $scope        Test scope whose descendant `new` expressions are checked for clock reads.
     *
     * @return list<Finding> - one finding per current-time DateTime construction in this scope; empty when every construction uses a fixed timestamp
     */
    private function dateTimeFindings(AnalysisUnit $analysisUnit, TestQualityScope $scope): array
    {
        $findings = [];

        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\New_::class]) as $newExpression) {
            if ($this->isWallClockDateTimeConstructor($newExpression)) {
                $findings[] = $this->dateTimeFinding($analysisUnit, $scope, $newExpression);
            }
        }

        // One finding per current-time DateTime construction in this scope; fixed-timestamp ones are left alone.
        return $findings;
    }

    /**
     * Detect whether a `new DateTime(...)` / `new DateTimeImmutable(...)` reads the current time.
     *
     * @param Expr\New_ $newExpression Object-construction node; only DateTime-family classes are considered.
     *
     * @return bool - true when the class is a DateTime variant constructed with "now" or no argument; false for any non-DateTime class or a
     *              fixed-timestamp construction
     */
    private function isWallClockDateTimeConstructor(Expr\New_ $newExpression): bool
    {
        if (!$newExpression->class instanceof Name) {
            // Dynamic class (new $var) can't be resolved statically, so it can't be confirmed as a clock read.
            return false;
        }

        $className = strtolower($newExpression->class->getLast());

        // True only for a DateTime-family class AND a current-time argument; both conditions are required.
        return in_array($className, self::WALL_CLOCK_DATETIME_CLASSES, true)
               && $this->isWallClockDateTime($newExpression);
    }

    /**
     * Detect whether the DateTime constructor argument is empty or the literal string "now".
     *
     * @param Expr\New_ $newExpression Construction node whose first argument is examined; non-literal args are treated
     *                                 as not-now so only provably current-time constructions are flagged.
     *
     * @return bool - true when no argument is passed or the first argument is the literal "now"; false when a fixed or non-literal argument is given
     */
    private function isWallClockDateTime(Expr\New_ $newExpression): bool
    {
        if ($newExpression->args === []) {
            // No argument means DateTime defaults to "now", so this reads the wall clock.
            return true;
        }

        $first = $newExpression->args[0];
        if (!$first instanceof Arg) {
            // Spread/placeholder argument we can't evaluate; stay conservative and do not flag.
            return false;
        }

        $literalValue = TestQualityNodeHelper::literalValue($first->value);

        // Only the literal string "now" counts as a current-time read; any other literal is a fixed instant.
        return is_string($literalValue) && strtolower($literalValue) === 'now';
    }

    /**
     * Build the Finding for a sleep / usleep / time_nanosleep call inside a test.
     *
     * @param AnalysisUnit     $analysisUnit Parsed unit supplying the display path stamped onto the finding.
     * @param TestQualityScope $scope        Enclosing test scope; its symbol names the offending test in the message.
     * @param Expr\FuncCall    $call         The sleep call; its start line locates the finding for the reviewer.
     * @param string           $name         Lowercased sleep-function name recorded in the finding metadata.
     *
     * @return Finding - warning finding tagged as the "sleep" variant, anchored at the call's start line, naming the offending test
     */
    private function sleepFinding(AnalysisUnit $analysisUnit, TestQualityScope $scope, Expr\FuncCall $call, string $name): Finding
    {
        // Warning that this test blocks on a real sleep; metadata tags it as the "sleep" variant for downstream filtering.
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
     * @param AnalysisUnit     $analysisUnit Parsed unit supplying the display path stamped onto the finding.
     * @param TestQualityScope $scope        Enclosing test scope; its symbol names the offending test in the message.
     * @param Expr\FuncCall    $call         The wall-clock call; its start line locates the finding for the reviewer.
     * @param string           $name         Lowercased clock-function name woven into the message and metadata.
     *
     * @return Finding - warning finding tagged as the "wall-clock" variant, anchored at the call's start line, naming the offending test
     */
    private function wallClockFunctionFinding(AnalysisUnit $analysisUnit, TestQualityScope $scope, Expr\FuncCall $call, string $name): Finding
    {
        // Warning that this test reads real time; metadata tags it as the "wall-clock" variant for downstream filtering.
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
     * @param AnalysisUnit     $analysisUnit  Parsed unit supplying the display path stamped onto the finding.
     * @param TestQualityScope $scope         Enclosing test scope; its symbol names the offending test in the message.
     * @param Expr\New_        $newExpression The current-time construction; its start line and class name feed the finding.
     *                                        Caller must guarantee a named class, or this method throws LogicException.
     *
     * @return Finding - warning finding tagged as the "datetime" variant, anchored at the construction's start line, naming the offending test
     */
    private function dateTimeFinding(AnalysisUnit $analysisUnit, TestQualityScope $scope, Expr\New_ $newExpression): Finding
    {
        if (!$newExpression->class instanceof Name) {
            throw new \LogicException('DateTime finding requires a named class.');
        }

        $className = $newExpression->class;

        // Warning that this test pins to the current instant; metadata tags it as the "datetime" variant for filtering.
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
