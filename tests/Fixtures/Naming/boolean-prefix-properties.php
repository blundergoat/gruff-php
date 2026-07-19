<?php

declare(strict_types=1);

namespace Fixtures\Naming;

final class BooleanPrefixPropertiesFixture
{
    public bool $isPest       = false;
    public bool $active       = true;
    public bool $emitted      = false;
    public bool $changedOnly  = false;
    public bool $infectionRun = false;
    public ?bool $valid       = null;
    public bool|null $silent  = null;
    public bool|string $flag  = false;
    public bool $is_valid     = false;
    public bool $force        = false;
    public bool $forceShould  = false;

    public function __construct(private bool $interactive, private bool $infectionRunCtor)
    {
    }

    public function configure(bool $active, bool $isPest, bool $changedOnly, ?bool $strict, bool|null $infectionRun, bool|string $flag): void
    {
        echo $active ? 'active' : 'inactive';
        echo $isPest ? 'pest' : 'phpunit';
        echo $changedOnly ? 'changed' : 'all';
        echo $strict === true ? 'strict' : 'loose';
        echo $infectionRun === true ? 'infection' : 'plain';
        echo is_bool($flag) && $flag ? 'flagged' : 'clear';
    }
}

/**
 * Exercises default state, proposition, and retained vague names on declared properties.
 * The Boolean naming rule reaches these declarations after type and negative-name checks.
 * Users encounter them when state vocabulary is clearer than a forced predicate prefix.
 */
final class BooleanStatePropertyFixture
{
    /** Whether a focus-mode payload is present for the current request. */
    protected bool $focusModePayloadPresent = false;

    /** Whether today's printable schedule was requested. */
    protected bool $printableTodayScheduleRequested = false;

    /** Whether a booking requires confirmation. */
    protected bool $bookingRequiresConfirmation = false;

    /** Whether the state has been limited by policy. */
    protected bool $limited = false;

    /** Whether the state can be printed for the user. */
    protected bool $printable = false;

    /** Vague data flag retained as a naming finding. */
    protected bool $data = false;

    /** Vague result flag retained as a naming finding. */
    protected bool $result = false;

    /** Vague mode flag retained as a naming finding. */
    protected bool $mode = false;

    /** Unevidenced required adjective retained until explicitly configured. */
    protected bool $required = false;
}

/**
 * Exercises Boolean vocabulary on constructor-promoted properties.
 * Promotion sends these parameters through the same naming path as stored state.
 * Users reach this shape when immutable input objects expose named Boolean state.
 */
final class BooleanStatePromotionFixture
{
    /**
     * Store promoted Boolean states spanning suffix, proposition, adjective, and vague-name cases.
     *
     * @param bool $paymentRequested - True when payment was requested; false otherwise.
     * @param bool $assistantIntentRequiresContext - True when the intent needs context; false otherwise.
     * @param bool $resolved - True when the value has resolved; false otherwise.
     * @param bool $data - Vague retained flag used to prove the default does not overreach.
     */
    public function __construct(
        protected bool $paymentRequested,
        protected bool $assistantIntentRequiresContext,
        protected bool $resolved,
        protected bool $data,
    ) {
    }
}

/**
 * Exercises Boolean vocabulary on ordinary method parameters.
 * The method consumes every input so only the naming rule determines the fixture outcome.
 * Users reach this shape when caller-visible named arguments describe state or propositions.
 */
final class BooleanStateParameterFixture
{
    /**
     * Render configured state values while exposing each naming mechanism to analysis.
     *
     * @param bool $declineCodeExplanationRequested - True when an explanation was requested; false otherwise.
     * @param bool $focusModePayloadPresent - True when the payload is present; false otherwise.
     * @param bool $assistantIntentRequiresContext - True when the intent needs context; false otherwise.
     * @param bool $limited - True when the result is limited; false otherwise.
     * @param bool $result - Vague retained flag that must continue to report.
     *
     * @return string - Colon-delimited state values for fixture consumption; never empty for the fixed inputs.
     */
    public function stateSummary(
        bool $declineCodeExplanationRequested,
        bool $focusModePayloadPresent,
        bool $assistantIntentRequiresContext,
        bool $limited,
        bool $result,
    ): string {
        return implode(':', [
            $declineCodeExplanationRequested ? 'requested' : 'not-requested',
            $focusModePayloadPresent ? 'present' : 'absent',
            $assistantIntentRequiresContext ? 'context' : 'standalone',
            $limited ? 'limited' : 'complete',
            $result ? 'result' : 'no-result',
        ]);
    }
}
