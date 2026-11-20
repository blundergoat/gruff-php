<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Output\Reporter\FailThreshold;
use GruffPhp\Output\Reporter\FailThresholds;
use GruffPhp\Output\Reporter\ThresholdTrip;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers legacy --fail-on gates and failureConditions parsing and validation.
 * Checks severity and total caps plus reported breach details.
 * Exercises new-findings scope and precedence over the full finding set.
 */
final class FailThresholdsTest extends TestCase
{
    /**
     * Verify fromFailOn(Error) trips only on error findings.
     *
     * @return void - Asserts no trip for advisory and warning findings and a trip for an error finding.
     */
    public function testFromFailOnErrorTripsOnlyOnErrors(): void
    {
        $gate = FailThresholds::fromFailOn(FailThreshold::Error);

        self::assertNull($gate->tripsOn([$this->finding(Severity::Advisory), $this->finding(Severity::Warning)]));
        self::assertInstanceOf(ThresholdTrip::class, $gate->tripsOn([$this->finding(Severity::Error)]));
    }

    /**
     * Verify fromFailOn(Warning) trips on warning and error but not advisory.
     *
     * @return void - Asserts separate warning and error trips and no trip for an advisory finding.
     */
    public function testFromFailOnWarningTripsOnWarningAndError(): void
    {
        $gate = FailThresholds::fromFailOn(FailThreshold::Warning);

        self::assertNull($gate->tripsOn([$this->finding(Severity::Advisory)]));
        self::assertInstanceOf(ThresholdTrip::class, $gate->tripsOn([$this->finding(Severity::Warning)]));
        self::assertInstanceOf(ThresholdTrip::class, $gate->tripsOn([$this->finding(Severity::Error)]));
    }

    /**
     * Verify the advisory gate trips on one advisory and the disabled gate accepts two errors.
     *
     * @return void - Asserts an advisory trip and no trip from the disabled gate for the supplied error findings.
     */
    public function testFromFailOnAdvisoryTripsOnAnyAndNoneNeverTrips(): void
    {
        self::assertInstanceOf(
            ThresholdTrip::class,
            FailThresholds::fromFailOn(FailThreshold::Advisory)->tripsOn([$this->finding(Severity::Advisory)]),
        );
        self::assertNull(
            FailThresholds::fromFailOn(FailThreshold::None)->tripsOn([$this->finding(Severity::Error), $this->finding(Severity::Error)]),
        );
    }

    /**
     * Verify two errors meet an error cap of two and a third reports the exceeded cap.
     *
     * @return void - Asserts no trip for two errors, then an error trip with count three and cap two.
     */
    public function testSeverityCapAllowsUpToCapThenTrips(): void
    {
        $errorCap = 2;
        $gate     = FailThresholds::fromConfig(['severityThresholds' => ['error' => $errorCap]]);

        self::assertNull($gate->tripsOn([$this->finding(Severity::Error), $this->finding(Severity::Error)]));

        $overCap = [$this->finding(Severity::Error), $this->finding(Severity::Error), $this->finding(Severity::Error)];
        $trip    = $gate->tripsOn($overCap);
        self::assertInstanceOf(ThresholdTrip::class, $trip);
        self::assertSame('error', $trip->thresholdKind);
        self::assertSame(count($overCap), $trip->count);
        self::assertSame($errorCap, $trip->cap);
    }

    /**
     * Verify three advisory findings exceed a total cap of two and report the total count.
     *
     * @return void - Asserts a total-threshold trip with count three for the advisory-only input.
     */
    public function testTotalCapTripsRegardlessOfSeverity(): void
    {
        $gate = FailThresholds::fromConfig(['total' => 2]);

        $trip = $gate->tripsOn([
                                   $this->finding(Severity::Advisory),
                                   $this->finding(Severity::Advisory),
                                   $this->finding(Severity::Advisory),
                               ]);
        self::assertInstanceOf(ThresholdTrip::class, $trip);
        self::assertSame(ThresholdTrip::KIND_TOTAL, $trip->thresholdKind);
        self::assertSame(3, $trip->count);
    }

    /**
     * Verify an error breach is reported before a warning breach when both caps are exceeded.
     *
     * @return void - Asserts the error threshold is reported for findings ordered warning then error.
     */
    public function testReportsMostSevereTripFirst(): void
    {
        $gate = FailThresholds::fromConfig(['severityThresholds' => ['error' => 0, 'warning' => 0]]);

        $trip = $gate->tripsOn([$this->finding(Severity::Warning), $this->finding(Severity::Error)]);
        self::assertInstanceOf(ThresholdTrip::class, $trip);
        self::assertSame('error', $trip->thresholdKind);
    }

    /**
     * Verify fromConfig rejects malformed failureConditions with a descriptive ConfigException.
     *
     * @param string $configJson - Malformed failureConditions block encoded as JSON.
     * @param string $expectedMessage - Text that the ConfigException message must contain.
     *
     * @return void - Asserts ConfigException with the expected message text for each supplied invalid configuration.
     * @throws JsonException - If decoding the supplied JSON fails.
     */
    #[DataProvider('invalidFailureConditionsProvider')]
    public function testFromConfigRejectsInvalidFailureConditions(string $configJson, string $expectedMessage): void
    {
        /** @var array<array-key, mixed> $config - Decoded failureConditions block fed to the parser under test. */
        $config = json_decode($configJson, true, 16, JSON_THROW_ON_ERROR);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($expectedMessage);

        FailThresholds::fromConfig($config);
    }

    /**
     * Invalid failureConditions blocks encoded as valid JSON, paired with required exception-message text.
     *
     * @return iterable<string, array{string, string}> - Named pairs of config JSON and text the exception message must contain.
     */
    public static function invalidFailureConditionsProvider(): iterable
    {
        yield 'unknown top-level key' => [
            '{"totals": 1}',
            'Unknown config key "failureConditions.totals".',
        ];
        yield 'unknown severity name' => [
            '{"severityThresholds": {"critical": 1}}',
            'Unknown severity "critical" in failureConditions.severityThresholds. Use advisory, warning, or error.',
        ];
        yield 'non-integer threshold' => [
            '{"severityThresholds": {"error": "lots"}}',
            'Config key "failureConditions.severityThresholds.error" must be a non-negative integer.',
        ];
        yield 'doubly-nested newFindings' => [
            '{"newFindings": {"newFindings": {"total": 1}}}',
            'Unknown config key "failureConditions.newFindings.newFindings".',
        ];
    }

    /**
     * Verify the constructor rejects a negative error-severity cap.
     *
     * @return void - Expects InvalidArgumentException with message text identifying the negative error cap.
     */
    public function testConstructorRejectsNegativeCap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Severity cap for "error" must be a non-negative integer.');

        new FailThresholds(null, ['error' => -1]);
    }

    /**
     * Verify tripsOnScope reports a new-findings breach with the "new" scope.
     *
     * @return void - Asserts the new scope and error threshold for a breach in the new-findings gate.
     */
    public function testTripsOnScopeReportsNewScope(): void
    {
        $gate = (new FailThresholds(null, []))->withNewFindingsGate(new FailThresholds(null, ['error' => 0]));

        $trip = $gate->tripsOnScope([], [$this->finding(Severity::Error)]);
        self::assertInstanceOf(ThresholdTrip::class, $trip);
        self::assertSame(ThresholdTrip::SCOPE_NEW, $trip->scope);
        self::assertSame('error', $trip->thresholdKind);
    }

    /**
     * Verify a full-set error breach has total scope when no new-findings gate is attached.
     *
     * @return void - Asserts total scope for the full-set error finding with no new-findings gate.
     */
    public function testTripsOnScopeReportsTotalScopeWhenOnlyTotalTrips(): void
    {
        $gate = new FailThresholds(null, ['error' => 0]);

        $trip = $gate->tripsOnScope([$this->finding(Severity::Error)], []);
        self::assertInstanceOf(ThresholdTrip::class, $trip);
        self::assertSame(ThresholdTrip::SCOPE_TOTAL, $trip->scope);
    }

    /**
     * Verify a new-findings breach takes precedence when both finding sets exceed their error caps.
     *
     * @return void - Asserts new scope when the supplied findings exceed both gates' error caps.
     */
    public function testTripsOnScopeNewGateWinsWhenBothTrip(): void
    {
        $gate = (new FailThresholds(null, ['error' => 0]))->withNewFindingsGate(new FailThresholds(null, ['error' => 0]));

        $trip = $gate->tripsOnScope([$this->finding(Severity::Error)], [$this->finding(Severity::Error)]);
        self::assertInstanceOf(ThresholdTrip::class, $trip);
        self::assertSame(ThresholdTrip::SCOPE_NEW, $trip->scope);
    }

    /**
     * Verify tripsOnScope returns null when neither gate trips.
     *
     * @return void - Asserts null when both finding sets contain one error below their caps of five.
     */
    public function testTripsOnScopeReturnsNullWhenNeitherTrips(): void
    {
        $gate = (new FailThresholds(null, ['error' => 5]))->withNewFindingsGate(new FailThresholds(null, ['error' => 5]));

        self::assertNull($gate->tripsOnScope([$this->finding(Severity::Error)], [$this->finding(Severity::Error)]));
    }

    /**
     * Verify fromConfig parses a newFindings sub-gate.
     *
     * @return void - Asserts a parsed new-findings gate with an error cap of zero.
     */
    public function testFromConfigParsesNewFindingsSubGate(): void
    {
        $gate = FailThresholds::fromConfig(['newFindings' => ['severityThresholds' => ['error' => 0]]]);

        self::assertInstanceOf(FailThresholds::class, $gate->newFindingsGate);
        self::assertSame(['error' => 0], $gate->newFindingsGate->severityCounts);
    }

    /**
     * Build a finding at the requested severity for gate evaluation.
     *
     * @param Severity $severity - Severity to attach to the finding.
     *
     * @return Finding - a fixed documentation-pillar finding so a test can exercise one severity band of the gate
     */
    private function finding(Severity $severity): Finding
    {
        return new Finding(
            ruleId:     'rule.example',
            message:    'Example finding.',
            filePath:   'src/Example.php',
            line:       1,
            severity:   $severity,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );
    }
}
