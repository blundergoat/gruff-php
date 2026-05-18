<?php

declare(strict_types=1);

namespace Fixtures\Naming;

final class BookingSession
{
}

final class ClosureCoverageFixture
{
    public function run(BookingSession $bookingSession): void
    {
        $invoiceTotal = 10;

        $customerFormatter = function (string $customerName) use ($invoiceTotal): string {
            return $customerName . (string) $invoiceTotal;
        };

        $singleCharacterArrow = fn ($x): int => $x + 1;

        $qualityClosure = function ($foo) use ($invoiceTotal): void {
            $tmp = $foo;

            echo $tmp . (string) $invoiceTotal;
        };

        $hungarianClosure = function (): void {
            $strName = 'Ada';

            echo $strName;
        };

        $booleanClosure = function (bool $changedOnly): bool {
            return $changedOnly;
        };

        $typedClosure = function (BookingSession $session): void {
            echo $session instanceof BookingSession ? 'booking' : 'missing';
        };

        $shadowingClosure = function (string $invoiceId): callable {
            return function (string $invoiceId): string {
                return $invoiceId;
            };
        };

        echo $customerFormatter('Ada');
        echo (string) $singleCharacterArrow(1);
        $qualityClosure();
        $hungarianClosure();
        echo $booleanClosure(true) ? 'changed' : 'all';
        $typedClosure($bookingSession);
        echo $shadowingClosure('INV-1')('INV-2');
    }
}
