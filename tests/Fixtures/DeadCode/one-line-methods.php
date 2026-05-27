<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

final class OneLineMethodFixture
{
    public function isEligible(BookingSession $session, BookingRequestContext $requestContext): bool
    {
        return $this->rejectionReason($session, $requestContext) === null;
    }

    public function sharedHelper(BookingSession $session, BookingRequestContext $requestContext): ?string
    {
        return $this->rejectionReason($session, $requestContext);
    }

    public function usesSharedHelperForPrimaryPath(BookingSession $session, BookingRequestContext $requestContext): ?string
    {
        return $this->sharedHelper($session, $requestContext);
    }

    public function usesSharedHelperForSecondaryPath(BookingSession $session, BookingRequestContext $requestContext): ?string
    {
        return $this->sharedHelper($session, $requestContext);
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

final class AlternativeFactoryFixture
{
    private function __construct(private string $status)
    {
    }

    public static function ready(string $status): self
    {
        return new self($status);
    }

    public static function failed(string $status): self
    {
        return new self($status);
    }
}

final class SingleFactoryFixture
{
    private function __construct(private string $status)
    {
    }

    public static function only(string $status): self
    {
        return new self($status);
    }
}

final class CrossClassCallerOwnerA
{
    public function persist(BookingSession $session): void
    {
        $this->save($session);
    }

    public function alsoPersist(BookingSession $session): void
    {
        $this->save($session);
    }

    private function save(BookingSession $session): void
    {
        unset($session);
    }
}

final class CrossClassCallerOwnerB
{
    public function save(BookingSession $session): BookingSession
    {
        return $this->normalise($session);
    }

    private function normalise(BookingSession $session): BookingSession
    {
        return $session;
    }
}
