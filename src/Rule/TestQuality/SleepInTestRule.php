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

final readonly class SleepInTestRule implements RuleInterface
{
    public const ID = 'test-quality.sleep-in-test';

    private const SLEEP_FUNCTIONS = ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'];
    private const WALL_CLOCK_FUNCTIONS = ['time', 'microtime'];
    private const WALL_CLOCK_DATETIME_CLASSES = ['datetime', 'datetimeimmutable'];

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

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $findings = [];
        $finder = new NodeFinder();

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                $name = TestQualityNodeHelper::callName($call);
                if ($name === null) {
                    continue;
                }

                if (in_array($name, self::SLEEP_FUNCTIONS, true) && $call instanceof Expr\FuncCall) {
                    $findings[] = $this->finding(
                        $unit,
                        $scope,
                        $call->getStartLine(),
                        sprintf('%s sleeps during the test run, which is a flakiness and latency smell.', $scope->symbol),
                        'Replace sleeps with explicit clocks, retries with deadlines, or observable synchronization points.',
                        ['variant' => 'sleep', 'function' => $name],
                    );
                    continue;
                }

                if (in_array($name, self::WALL_CLOCK_FUNCTIONS, true) && $call instanceof Expr\FuncCall) {
                    $findings[] = $this->finding(
                        $unit,
                        $scope,
                        $call->getStartLine(),
                        sprintf('%s reads the wall clock via %s(), which couples the test to real time.', $scope->symbol, $name),
                        'Inject a fake clock or fixed timestamp instead of calling time()/microtime() directly.',
                        ['variant' => 'wall-clock', 'function' => $name],
                    );
                }
            }

            foreach ($finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\New_) as $new) {
                if (!$new instanceof Expr\New_ || !$new->class instanceof Name) {
                    continue;
                }

                $className = strtolower($new->class->getLast());
                if (!in_array($className, self::WALL_CLOCK_DATETIME_CLASSES, true)) {
                    continue;
                }

                if (!$this->isWallClockDateTime($new)) {
                    continue;
                }

                $findings[] = $this->finding(
                    $unit,
                    $scope,
                    $new->getStartLine(),
                    sprintf('%s constructs %s with the current time, which couples the test to real time.', $scope->symbol, $new->class->toString()),
                    'Pass a fixed timestamp to the DateTime constructor or inject a fake clock.',
                    ['variant' => 'datetime', 'class' => $new->class->toString()],
                );
            }
        }

        return $findings;
    }

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
     * @param array<string, scalar> $metadata
     */
    private function finding(
        AnalysisUnit $unit,
        TestQualityScope $scope,
        int $line,
        string $message,
        string $remediation,
        array $metadata,
    ): Finding {
        return new Finding(
            ruleId: self::ID,
            message: $message,
            filePath: $unit->file->displayPath,
            line: $line,
            severity: Severity::Warning,
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            confidence: Confidence::High,
            symbol: $scope->symbol,
            remediation: $remediation,
            metadata: $metadata,
        );
    }
}
