<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

final class OneLineMethodFixture
{
    public function isEligible(BookingSession $session, BookingRequestContext $requestContext): bool
    {
        return $this->rejectionReason($session, $requestContext) === null;
    }

    public function formatGreeting(string $name): string
    {
        return 'Hello, ' . $name;
    }

    public function getName(): string
    {
        return $this->name();
    }

    public function testItUsesFixture(): void
    {
        $this->assertTrue(true);
    }

    private function rejectionReason(BookingSession $session, BookingRequestContext $requestContext): ?string
    {
        return null;
    }

    private function name(): string
    {
        return 'booking';
    }

    private function assertTrue(bool $value): void
    {
    }
}

final class BookingSession
{
}

final class BookingRequestContext
{
}
