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
        $gate = FailThresholds::fromConfig(['severityThresholds' => ['error' => 2]]);

        self::assertNull($gate->tripsOn([$this->finding(Severity::Error), $this->finding(Severity::Error)]));

        $trip = $gate->tripsOn([$this->finding(Severity::Error), $this->finding(Severity::Error), $this->finding(Severity::Error)]);
        self::assertInstanceOf(ThresholdTrip::class, $trip);
        self::assertSame('error', $trip->thresholdKind);
        self::assertSame(3, $trip->count);
        self::assertSame(2, $trip->cap);
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
     * Verify fromConfig rejects an unknown top-level key.
     *
     * @return void
     */
    public function testFromConfigRejectsUnknownKey(): void
    {
        $this->expectException(ConfigException::class);

        FailThresholds::fromConfig(['totals' => 1]);
    }

    /**
     * Verify fromConfig rejects an unknown severity name.
     *
     * @return void
     */
    public function testFromConfigRejectsUnknownSeverity(): void
    {
        $this->expectException(ConfigException::class);

        FailThresholds::fromConfig(['severityThresholds' => ['critical' => 1]]);
    }

    /**
     * Verify fromConfig rejects a non-integer threshold value.
     *
     * @return void
     */
    public function testFromConfigRejectsNonIntThreshold(): void
    {
        $this->expectException(ConfigException::class);

        FailThresholds::fromConfig(['severityThresholds' => ['error' => 'lots']]);
    }

    /**
     * Verify the constructor refuses negative caps.
     *
     * @return void
     */
    public function testConstructorRejectsNegativeCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FailThresholds(null, ['error' => -1]);
    }

    /**
     * Build a finding at the requested severity for gate evaluation.
     *
     * @param Severity $severity Severity to attach to the finding.
     * @return Finding Finding carrying the requested severity.
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
