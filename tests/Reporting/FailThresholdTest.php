<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Finding\Severity;
use GruffPhp\Reporting\FailThreshold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the FailThreshold parser and trigger contract.
 */
final class FailThresholdTest extends TestCase
{
    /**
     * Each canonical CLI value parses into the matching enum case.
     *
     * @param string        $rawInput Raw CLI value supplied to FailThreshold::fromInput.
     * @param FailThreshold $expected Enum case the parser must return for the canonical value.
     *
     * @return void
     */
    #[DataProvider('canonicalValuesProvider')]
    public function testFromInputAcceptsCanonicalValue(string $rawInput, FailThreshold $expected): void
    {
        self::assertSame($expected, FailThreshold::fromInput($rawInput));
    }

    /**
     * Banned aliases, case variants, and the empty string return null.
     *
     * @param string $rawInput Raw CLI value the parser must reject as unsupported.
     *
     * @return void
     */
    #[DataProvider('rejectedValuesProvider')]
    public function testFromInputRejectsBannedValue(string $rawInput): void
    {
        self::assertNull(FailThreshold::fromInput($rawInput));
    }

    /**
     * isTriggeredBy returns the documented value for every threshold/severity pair.
     *
     * @param FailThreshold $threshold     Threshold under test.
     * @param Severity      $severity      Finding severity compared against the threshold.
     * @param bool          $shouldTrigger Expected isTriggeredBy result for this pair.
     *
     * @return void
     */
    #[DataProvider('triggerMatrixProvider')]
    public function testIsTriggeredBy(FailThreshold $threshold, Severity $severity, bool $shouldTrigger): void
    {
        self::assertSame($shouldTrigger, $threshold->isTriggeredBy($severity));
    }

    /**
     * Canonical CLI fail-on values paired with the enum case the parser must return.
     *
     * @return iterable<string, array{string, FailThreshold}> - one data row per canonical CLI fail-on value, each pairing the raw input with the
     *                          enum case the parser must return; covers all four cases
     */
    public static function canonicalValuesProvider(): iterable
    {
        yield 'advisory' => ['advisory', FailThreshold::Advisory];
        yield 'warning' => ['warning', FailThreshold::Warning];
        yield 'error' => ['error', FailThreshold::Error];
        yield 'none' => ['none', FailThreshold::None];
    }

    /**
     * Inputs the parser must reject as unsupported (banned aliases, case variants, empty string).
     *
     * @return iterable<string, array{string}> - one data row per input the parser must reject (banned aliases, case variants, empty string), each
     *                          wrapping the single raw value expected to yield null
     */
    public static function rejectedValuesProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'never' => ['never'];
        yield 'off' => ['off'];
        yield 'disabled' => ['disabled'];
        yield 'medium' => ['medium'];
        yield 'low' => ['low'];
        yield 'high' => ['high'];
        yield 'critical' => ['critical'];
        yield 'info' => ['info'];
        yield 'notice' => ['notice'];
        yield 'warn' => ['warn'];
        yield 'NONE (upper-case)' => ['NONE'];
        yield 'Advisory (mixed-case)' => ['Advisory'];
    }

    /**
     * Every (threshold, severity) pair paired with the documented isTriggeredBy result.
     *
     * @return iterable<string, array{FailThreshold, Severity, bool}> - one data row per (threshold, severity) combination, each carrying the pair
     *                          and the expected isTriggeredBy outcome; covers the full matrix
     */
    public static function triggerMatrixProvider(): iterable
    {
        yield 'None / Advisory' => [FailThreshold::None, Severity::Advisory, false];
        yield 'None / Warning' => [FailThreshold::None, Severity::Warning, false];
        yield 'None / Error' => [FailThreshold::None, Severity::Error, false];
        yield 'Advisory / Advisory' => [FailThreshold::Advisory, Severity::Advisory, true];
        yield 'Advisory / Warning' => [FailThreshold::Advisory, Severity::Warning, true];
        yield 'Advisory / Error' => [FailThreshold::Advisory, Severity::Error, true];
        yield 'Warning / Advisory' => [FailThreshold::Warning, Severity::Advisory, false];
        yield 'Warning / Warning' => [FailThreshold::Warning, Severity::Warning, true];
        yield 'Warning / Error' => [FailThreshold::Warning, Severity::Error, true];
        yield 'Error / Advisory' => [FailThreshold::Error, Severity::Advisory, false];
        yield 'Error / Warning' => [FailThreshold::Error, Severity::Warning, false];
        yield 'Error / Error' => [FailThreshold::Error, Severity::Error, true];
    }
}
