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

interface CapabilityRowsContract
{
    public function rows(): array;
}

abstract class AbstractPayloadAdapter
{
    abstract public function normalisePayload(array $payload): array;
}

trait RequiresPayloadLabel
{
    abstract public function labelFor(array $row): string;
}

final class ContractRowsAdapter implements CapabilityRowsContract
{
    public function rows(): array
    {
        return DataCapabilities::rows();
    }
}

final class AbstractPayloadAdapterImplementation extends AbstractPayloadAdapter
{
    public function normalisePayload(array $payload): array
    {
        return PayloadNormaliser::normalise($payload);
    }
}

final class TraitPayloadLabelAdapter
{
    use RequiresPayloadLabel;

    public function labelFor(array $row): string
    {
        return PayloadNormaliser::label($row);
    }
}

final readonly class PracticeAssistantSessionDto
{
    public function __construct(private string $sessionId)
    {
    }

    public function sessionId(): string
    {
        return PayloadNormaliser::string($this->sessionId);
    }
}

final class DomainVocabularyWrapper
{
    public function supportsPracticeAssistant(array $payload): bool
    {
        return PayloadNormaliser::matches($payload, 'practice_assistant');
    }
}

final class PrivatePassThroughFixture
{
    public function useHelper(BookingSession $session): array
    {
        return [$this->oneShotHelper($session)];
    }

    private function rows(): array
    {
        return DataCapabilities::rows();
    }

    private function oneShotHelper(BookingSession $session): BookingSession
    {
        return PayloadNormaliser::session($session);
    }
}

final class DataCapabilities
{
    public static function rows(): array
    {
        return [];
    }
}

final class PayloadNormaliser
{
    public static function normalise(array $payload): array
    {
        return $payload;
    }

    public static function label(array $row): string
    {
        return (string) ($row['label'] ?? '');
    }

    public static function string(string $value): string
    {
        return trim($value);
    }

    public static function matches(array $payload, string $type): bool
    {
        return ($payload['type'] ?? null) === $type;
    }

    public static function session(BookingSession $session): BookingSession
    {
        return $session;
    }
}
