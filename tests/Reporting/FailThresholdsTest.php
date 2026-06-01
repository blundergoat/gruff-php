<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Config\ConfigException;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\FailThresholds;
use GruffPhp\Reporting\ThresholdTrip;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the count-gate value object: legacy --fail-on parity, failureConditions
 * parsing and validation, and per-severity/total trip evaluation.
 */
final class FailThresholdsTest extends TestCase
{
    /**
     * Verify fromFailOn(Error) trips only on error findings, matching the legacy gate.
     *
     * @return void
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
     * @return void
     */
    public function testFromFailOnWarningTripsOnWarningAndError(): void
    {
        $gate = FailThresholds::fromFailOn(FailThreshold::Warning);

        self::assertNull($gate->tripsOn([$this->finding(Severity::Advisory)]));
        self::assertInstanceOf(ThresholdTrip::class, $gate->tripsOn([$this->finding(Severity::Warning)]));
        self::assertInstanceOf(ThresholdTrip::class, $gate->tripsOn([$this->finding(Severity::Error)]));
    }

    /**
     * Verify fromFailOn(Advisory) trips on any finding and fromFailOn(None) never trips.
     *
     * @return void
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
     * Verify a severity cap allows up to the cap then trips with the exceeded count.
     *
     * @return void
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
     * Verify the total cap trips regardless of severity distribution.
     *
     * @return void
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
     * Verify the most severe breach is reported first when several thresholds trip.
     *
     * @return void
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
     * @param string $configJson      Malformed failureConditions block encoded as JSON.
     * @param string $expectedMessage ConfigException message the parser must report.
     * @throws JsonException
     * @return void
     */
    #[DataProvider('invalidFailureConditionsProvider')]
    public function testFromConfigRejectsInvalidFailureConditions(string $configJson, string $expectedMessage): void
    {
        /** @var array<array-key, mixed> $config Decoded failureConditions block fed to the parser under test. */
        $config = json_decode($configJson, true, 16, JSON_THROW_ON_ERROR);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($expectedMessage);

        FailThresholds::fromConfig($config);
    }

    /**
     * Malformed failureConditions blocks (as JSON) paired with the ConfigException message each must raise.
     *
     * @return iterable<string, array{string, string}>
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
     * Verify the constructor refuses negative caps.
     *
     * @return void
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
     * @return void
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
     * Verify tripsOnScope falls through to the total gate with the "total" scope.
     *
     * @return void
     */
    public function testTripsOnScopeReportsTotalScopeWhenOnlyTotalTrips(): void
    {
        $gate = new FailThresholds(null, ['error' => 0]);

        $trip = $gate->tripsOnScope([$this->finding(Severity::Error)], []);
        self::assertInstanceOf(ThresholdTrip::class, $trip);
        self::assertSame(ThresholdTrip::SCOPE_TOTAL, $trip->scope);
    }

    /**
     * Verify the new-findings trip wins when both the new and total gates fire.
     *
     * @return void
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
     * @return void
     */
    public function testTripsOnScopeReturnsNullWhenNeitherTrips(): void
    {
        $gate = (new FailThresholds(null, ['error' => 5]))->withNewFindingsGate(new FailThresholds(null, ['error' => 5]));

        self::assertNull($gate->tripsOnScope([$this->finding(Severity::Error)], [$this->finding(Severity::Error)]));
    }

    /**
     * Verify fromConfig parses a newFindings sub-gate.
     *
     * @return void
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
     * @param Severity $severity Severity to attach to the finding.
     * @return Finding Finding carrying the requested severity.
     */
    private function finding(Severity $severity): Finding
    {
        // Hand back a finding fixed at the requested severity so a test can drive one threshold band.
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
