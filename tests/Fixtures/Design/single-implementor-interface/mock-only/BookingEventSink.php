<?php

declare(strict_types=1);

namespace Fixtures\Design\SingleImplementor\MockOnly;

/**
 * Interface used by one production class plus mocks-only test doubles.
 *
 * The rule's default policy treats mock-only usage as "no extra
 * implementor", so this still flags. The `treatMockUsageAsImplementor`
 * option flips the result when set to true.
 */
interface BookingEventSinkInterface
{
    public function record(string $event): void;
}

/**
 * The sole production implementor.
 */
final class NullBookingEventSink implements BookingEventSinkInterface
{
    public function record(string $event): void
    {
    }
}
