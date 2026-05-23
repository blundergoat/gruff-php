<?php

declare(strict_types=1);

namespace Fixtures\Naming;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class BookingSession
{
}

final class BookingIntent
{
}

final class BookingRequestContext
{
}

final class ParameterTypeNameFixture
{
    public function process(BookingSession $session, BookingIntent $intent, BookingRequestContext $requestContext): void
    {
    }

    public function alreadySpecific(BookingSession $bookingSession, BookingIntent $bookingIntent, BookingRequestContext $bookingRequestContext): void
    {
    }

    /**
     * Exercise duplicate same-type parameters that still include the type words.
     *
     * @param BookingSession $leftBookingSession Left session fixture.
     * @param BookingSession $rightBookingSession Right session fixture.
     * @return void
     */
    public function duplicateTypedPair(BookingSession $leftBookingSession, BookingSession $rightBookingSession): void
    {
        $leftType = get_debug_type($leftBookingSession);
        echo $leftType . get_debug_type($rightBookingSession);
    }

    /**
     * Exercise duplicate same-type parameters that omit the type words.
     *
     * @param BookingIntent $left  Left intent fixture.
     * @param BookingIntent $right Right intent fixture.
     * @return void
     */
    public function duplicateTypedPairStillNeedsType(BookingIntent $left, BookingIntent $right): void
    {
        $leftType = get_debug_type($left);
        echo $leftType . get_debug_type($right);
    }

    public function stripsInterfaceSuffix(EntityManagerInterface $entityManager): void
    {
    }

    public function ignoresBuiltins(string $name, array $items): void
    {
    }

    public function handlesNullable(?DateTimeImmutable $dateTimeImmutable): void
    {
    }

    public function unionNullableLeft(BookingSession|null $thing): void
    {
    }

    public function unionNullableRight(null|BookingSession $thing): void
    {
    }

    public function realUnion(BookingSession|BookingIntent $thing): void
    {
    }

    public function realIntersectionNullable((\Stringable&\Countable)|null $thing): void
    {
    }
}
