<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Security\SecurityNodeHelper;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Flags blocking pauses and wall-clock reads in tests, including current-time DateTime constructions.
 * Clock values used as uniqid entropy or a proved failure-only event-pump deadline stay quiet.
 * Runs over every test in the file. Warning, high confidence.
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
     * Describes the sleep-in-test rule for the registry and reports.
     *
     * @return RuleDefinition - static descriptor (id, name, pillar, tier, default severity, confidence) the registry uses to list and report this
     *                        rule
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Sleep or wall-clock read in test',
            pillar:             Pillar::TestQuality,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Warning,
            confidence:         Confidence::High,
            isEnabledByDefault: false,
        );
    }

    /**
     * Reports tests that sleep or read the wall clock, which make them flaky and slow.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - every sleep and wall-clock finding across all test scopes in this unit; empty when the unit has no tests or none offend
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            array_push(
                   $findings,
                ...$this->functionFindings($analysisUnit, $scope),
                ...$this->dateTimeFindings($analysisUnit, $scope),
            );
        }

        return $findings;
    }

    /**
     * Builds the sleep and wall-clock findings for a test's function calls.
     *
     * @param AnalysisUnit     $analysisUnit - Parsed unit supplying the display path stamped onto each finding.
     * @param TestQualityScope $scope        - Single test method scope whose calls are searched for sleeps/clock reads.
     *
     * @return list<Finding> - one finding per sleep or wall-clock function call in this scope; empty when no call matches either set
     */
    private function functionFindings(AnalysisUnit $analysisUnit, TestQualityScope $scope): array
    {
        $findings = [];

        // Inspect each call the test makes.
        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            // Only a global function call can be a sleep or clock read.
            if (!$call instanceof Expr\FuncCall) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            // A call with no resolvable name cannot be classified.
            if ($name === null) {
                continue;
            }

            $finding = $this->functionFinding($analysisUnit, $scope, $call, $name);
            // Keep the finding when the call was a sleep or clock read.
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Builds a finding for a sleep or wall-clock call, or null when it is neither.
     *
     * @param AnalysisUnit     $analysisUnit - Parsed unit supplying the display path stamped onto the finding.
     * @param TestQualityScope $scope        - Enclosing test scope, used for the symbol and message wording.
     * @param Expr\FuncCall    $call         - The call expression under inspection; its start line anchors the finding.
     * @param string           $name         - Lowercased called-function name already resolved from the call.
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
            // Entropy seeds and failure-only polling deadlines do not make success depend on elapsed time.
            if ($this->hasIncidentalClockPurpose($call, $scope)) {
                return null;
            }
            // time()/microtime(): real-time-coupling variant of the finding.
            return $this->wallClockFunctionFinding($analysisUnit, $scope, $call, $name);
        }

        // Call is neither sleep nor clock read, so it is harmless here and yields no finding.
        return null;
    }

    /**
     * Accepts a clock read only when its surrounding source proves a non-timing purpose.
     *
     * @param Expr\FuncCall    $call  - Wall-clock read being considered.
     * @param TestQualityScope $scope - Owning test, used to bind polling deadline assignments.
     * @return bool - True for a uniqid entropy seed or a bounded event-pump failure deadline.
     */
    private function hasIncidentalClockPurpose(Expr\FuncCall $call, TestQualityScope $scope): bool
    {
        for ($parent = $call->getAttribute('parent'); $parent instanceof Node && !$parent instanceof Node\FunctionLike; $parent = $parent->getAttribute('parent')) {
            if ($parent instanceof Expr\FuncCall && TestQualityNodeHelper::functionName($parent) === 'uniqid') {
                return true;
            }
        }

        foreach (NodeIndex::descendantsOfAny($scope->node, [Stmt\If_::class]) as $guard) {
            if ($this->isPollingDeadlineGuard($guard, $scope) && $guard->cond instanceof Expr\BinaryOp\GreaterOrEqual
                && $guard->cond->left instanceof Expr\FuncCall && $guard->cond->right instanceof Expr\Variable
                && in_array(TestQualityNodeHelper::functionName($guard->cond->left), self::WALL_CLOCK_FUNCTIONS, true)) {
                $initial = $this->deadlineClock($scope, $guard->cond->right, $guard->getStartFilePos());
                if ($initial !== null && ($call === $initial || $call === $guard->cond->left)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Requires an event-pump loop whose deadline branch can only fail the test.
     *
     * @param Stmt\If_         $guard - Candidate timeout branch directly inside a while loop.
     * @param TestQualityScope $scope - Owning test; unused callback bodies cannot establish an event pump.
     * @return bool - True when the branch cannot supply a timed success expectation.
     */
    private function isPollingDeadlineGuard(Stmt\If_ $guard, TestQualityScope $scope): bool
    {
        $loop = $guard->getAttribute('parent');
        if (!$loop instanceof Stmt\While_ || SecurityNodeHelper::enclosingFunctionLike($guard) !== $scope->node
            || $guard->else !== null || $guard->elseifs !== [] || count($guard->stmts) !== 1) {
            return false;
        }
        $statement = $guard->stmts[0];
        if (!$statement instanceof Stmt\Expression
            || (!$statement->expr instanceof Expr\MethodCall && !$statement->expr instanceof Expr\StaticCall)
            || TestQualityNodeHelper::callName($statement->expr) !== 'fail' || !$this->isPhpUnitFailure($statement->expr)) {
            return false;
        }
        foreach ((new NodeFinder())->findInstanceOf($loop->stmts, Expr\MethodCall::class) as $call) {
            if (TestQualityNodeHelper::callName($call) === 'tick' && SecurityNodeHelper::enclosingFunctionLike($call) === $scope->node) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identifies a terminal PHPUnit failure rather than a similarly named collaborator call.
     *
     * @param Expr\MethodCall|Expr\StaticCall $call - Failure call in the deadline branch.
     * @return bool - True for this, self or static receivers used by PHPUnit test methods.
     */
    private function isPhpUnitFailure(Expr\MethodCall|Expr\StaticCall $call): bool
    {
        if ($call instanceof Expr\MethodCall) {
            return $call->var instanceof Expr\Variable && $call->var->name === 'this';
        }

        return $call->class instanceof Name && in_array(strtolower($call->class->toString()), ['self', 'static'], true);
    }

    /**
     * Binds a single deadline assignment to a clock plus a positive literal duration.
     *
     * @param TestQualityScope $scope    - Test containing the deadline declaration and timeout guard.
     * @param Expr\Variable    $variable - Exact deadline variable compared by the guard.
     * @param int              $before   - Guard offset; a later declaration cannot establish an earlier bound.
     * @return Expr\FuncCall|null - Initial clock read, or null when the deadline is rebound or unproved.
     */
    private function deadlineClock(TestQualityScope $scope, Expr\Variable $variable, int $before): ?Expr\FuncCall
    {
        $assignments = [];
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\Assign::class]) as $assignment) {
            if ($assignment->var instanceof Expr\Variable && $assignment->var->name === $variable->name
                && SecurityNodeHelper::enclosingFunctionLike($assignment) === $scope->node) {
                $assignments[] = $assignment;
            }
        }
        if (count($assignments) !== 1 || $assignments[0]->getStartFilePos() >= $before) {
            return null;
        }
        $deadlineExpression = $assignments[0]->expr;
        if (!$deadlineExpression instanceof Expr\BinaryOp\Plus || !$deadlineExpression->left instanceof Expr\FuncCall
            || (!$deadlineExpression->right instanceof Scalar\Int_ && !$deadlineExpression->right instanceof Scalar\Float_)
            || $deadlineExpression->right->value <= 0) {
            return null;
        }

        return in_array(TestQualityNodeHelper::functionName($deadlineExpression->left), self::WALL_CLOCK_FUNCTIONS, true) ? $deadlineExpression->left : null;
    }

    /**
     * Builds the findings for current-time DateTime constructions in a test.
     *
     * @param AnalysisUnit     $analysisUnit - Parsed unit supplying the display path stamped onto each finding.
     * @param TestQualityScope $scope        - Test scope whose descendant `new` expressions are checked for clock reads.
     *
     * @return list<Finding> - one finding per current-time DateTime construction in this scope; empty when every construction uses a fixed timestamp
     */
    private function dateTimeFindings(AnalysisUnit $analysisUnit, TestQualityScope $scope): array
    {
        $findings = [];

        // Weigh every object construction in the test body.
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\New_::class]) as $newExpression) {
            // A current-time DateTime construction couples the test to real time.
            if ($this->isWallClockDateTimeConstructor($newExpression)) {
                $findings[] = $this->dateTimeFinding($analysisUnit, $scope, $newExpression);
            }
        }

        return $findings;
    }

    /**
     * Reports whether a DateTime construction reads the current time.
     *
     * @param Expr\New_ $newExpression - Object-construction node; only DateTime-family classes are considered.
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
     * Reports whether a DateTime constructor argument is empty or the literal "now".
     *
     * @param Expr\New_ $newExpression - Construction node whose first argument is examined; non-literal args are treated
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
     * Builds the finding for a sleep-family call inside a test.
     *
     * @param AnalysisUnit     $analysisUnit - Parsed unit supplying the display path stamped onto the finding.
     * @param TestQualityScope $scope        - Enclosing test scope; its symbol names the offending test in the message.
     * @param Expr\FuncCall    $call         - The sleep call; its start line locates the finding for the reviewer.
     * @param string           $name         - Lowercased sleep-function name recorded in the finding metadata.
     *
     * @return Finding - warning finding tagged as the "sleep" variant, anchored at the call's start line, naming the offending test
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
     * Builds the finding for a wall-clock read inside a test.
     *
     * @param AnalysisUnit     $analysisUnit - Parsed unit supplying the display path stamped onto the finding.
     * @param TestQualityScope $scope        - Enclosing test scope; its symbol names the offending test in the message.
     * @param Expr\FuncCall    $call         - The wall-clock call; its start line locates the finding for the reviewer.
     * @param string           $name         - Lowercased clock-function name woven into the message and metadata.
     *
     * @return Finding - warning finding tagged as the "wall-clock" variant, anchored at the call's start line, naming the offending test
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
     * Builds the finding for a current-time DateTime construction inside a test.
     *
     * @param AnalysisUnit     $analysisUnit  - Parsed unit supplying the display path stamped onto the finding.
     * @param TestQualityScope $scope         - Enclosing test scope; its symbol names the offending test in the message.
     * @param Expr\New_        $newExpression - The current-time construction; its start line and class name feed the finding.
     *                                        Caller must guarantee a named class, or this method throws LogicException.
     *
     * @return Finding - warning finding tagged as the "datetime" variant, anchored at the construction's start line, naming the offending test
     */
    private function dateTimeFinding(AnalysisUnit $analysisUnit, TestQualityScope $scope, Expr\New_ $newExpression): Finding
    {
        // The caller only reaches here for a named class, so guard the invariant.
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
