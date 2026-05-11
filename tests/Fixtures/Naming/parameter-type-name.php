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
