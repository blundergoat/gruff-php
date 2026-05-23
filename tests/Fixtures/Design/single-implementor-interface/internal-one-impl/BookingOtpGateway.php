<?php

declare(strict_types=1);

namespace Fixtures\Design\SingleImplementor\InternalOneImpl;

/**
 * Internal interface with one production implementor and no extra type-hint usage.
 *
 * This is the canonical "single-implementor interface" the rule targets.
 */
interface BookingOtpGatewayInterface
{
    public function send(string $phone, string $code): void;
}

/**
 * The sole implementor.
 */
final class FakeBookingOtpGateway implements BookingOtpGatewayInterface
{
    public function send(string $phone, string $code): void
    {
    }
}
