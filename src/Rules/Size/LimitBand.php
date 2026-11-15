<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Size;

use GruffPhp\Results\Finding\Severity;

/**
 * Assigns size and complexity findings to the bands in FAMILY-CONTRACT.md section 12.
 * Findings below 1.5 times the active limit use advisory severity and advice against further growth.
 *
 * Findings at or above that boundary retain their existing severity and use split-or-simplify advice.
 * Callers retain their message format across bands so band changes preserve finding identity.
 */
final class LimitBand
{
    /** Metadata key every banded finding carries. */
    public const KEY = 'limitBand';

    /** Band of a unit over its limit but under one and a half times it. */
    public const LOWER = 'lower';

    /** Band of a unit at one and a half times its limit or more. */
    public const UPPER = 'upper';

    /** Lower-band advice for method length and complexity. */
    public const LOWER_METHOD = 'Do not add to this method; put new code in a new method.';

    /** Lower-band advice for file length. */
    public const LOWER_FILE = 'Do not add to this file; put new code in a new file.';

    /** Lower-band advice for class length. */
    public const LOWER_CLASS = 'Do not add to this class; put new code in a new class.';

    /** Lower-band advice for parameter count. */
    public const LOWER_PARAMETER = 'Do not add another parameter to this method.';

    /** Lower-band advice for property count. */
    public const LOWER_PROPERTY = 'Do not add another property to this class.';

    /** Lower-band advice for public method count. */
    public const LOWER_PUBLIC_METHOD = 'Do not add another public method to this class.';

    /** Upper-band advice for file length. */
    public const SPLIT_FILE = 'Split this file by responsibility, one responsibility per file.';

    /** Upper-band advice for class length and member counts. */
    public const SPLIT_CLASS = 'Split this class by responsibility: move each group of members that work together into its own class.';

    /** Upper-band advice for method length. */
    public const SPLIT_METHOD = 'Split this method at its steps, one step per method.';

    /** Upper-band advice for parameter count. */
    public const GROUP_PARAMETERS = 'Group the parameters that travel together into one object, or split the method by caller.';

    /** Upper-band advice for every complexity rule. */
    public const SIMPLIFY_PATH = 'Simplify the execution path: return early, merge branches that lead to the same result, and drop flags that steer later branches. Moving branches into helpers leaves the path as hard to follow.';

    /** The fixed ratio at which a finding moves into the upper band; no option changes it. */
    private const RATIO = 1.5;

    /**
     * Names the band a measured value falls in against the limit in force, compared without rounding.
     *
     * @param int|float $measuredValue - The size or complexity the rule measured.
     * @param int|float $limit - The unit's limit, such as its lowest reporting threshold or a constructor-specific ceiling.
     *
     * @return string - LimitBand::UPPER at or above one and a half times the limit, otherwise LimitBand::LOWER.
     */
    public static function of(int|float $measuredValue, int|float $limit): string
    {

        if ($measuredValue >= self::RATIO * $limit) {
            return self::UPPER;
        }

        return self::LOWER;
    }

    /**
     * Gives a finding its band's severity: a lower-band finding is advisory whatever the rule's severity.
     *
     * @param string $band - The finding's band, from LimitBand::of.
     * @param Severity $severity - The severity the finding has without bands, after any softening by shape.
     *
     * @return Severity - Severity::Advisory in the lower band, otherwise the severity given.
     */
    public static function severity(string $band, Severity $severity): Severity
    {
        // A unit just over its limit is a notice not to grow, so it never carries the rule's louder severity.
        if ($band === self::LOWER) {
            return Severity::Advisory;
        }

        return $severity;
    }

    /**
     * Picks the advice for a finding's band.
     *
     * @param string $band - The finding's band, from LimitBand::of.
     * @param string $lowerAdvice - The do-not-grow advice for this kind of unit.
     * @param string $upperAdvice - The advice to split or simplify the unit.
     *
     * @return string - The lower-band advice in the lower band, otherwise the upper-band advice.
     */
    public static function advice(string $band, string $lowerAdvice, string $upperAdvice): string
    {

        if ($band === self::LOWER) {
            return $lowerAdvice;
        }

        return $upperAdvice;
    }
}
