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

/**
 * Exercises statically provable callback references that preserve useful method names.
 * The waste-rule fixture reaches each supported PHP callback spelling through real APIs.
 * Users rely on these named boundaries when a comparator or serializer explains intent.
 */
final class NamedCallbackBoundaryFixture
{
    /**
     * Invoke every supported callback form so the analyser can match its exact declaration.
     *
     * @param list<string> $rows - Values supplied to each named callback; an empty list produces an empty result.
     *
     * @return list<string> - Combined callback results; empty when no rows were supplied.
     */
    public function callbackRows(array $rows): array
    {
        usort($rows, self::compareRows(...));
        $serialised = array_map(self::serialiseRow(...), $rows);
        $fromThis   = array_map([$this, 'formatThisRow'], $rows);
        $fromSelf   = array_map([self::class, 'FORMATSELFROW'], $rows);
        $fromStatic = array_map([static::class, 'formatStaticRow'], $rows);
        $fromClass  = array_map([\Fixtures\DeadCode\NamedCallbackBoundaryFixture::class, 'formatClassRow'], $rows);
        $fromMagic  = array_map([__CLASS__, 'formatMagicRow'], $rows);

        return [
            ...$serialised,
            ...$fromThis,
            ...$fromSelf,
            ...$fromStatic,
            ...$fromClass,
            ...$fromMagic,
        ];
    }

    /**
     * Compare two rows while keeping the sort contract named at its call site.
     *
     * @param string $left - Left row value used for lexical comparison.
     * @param string $right - Right row value used for lexical comparison.
     *
     * @return int - Negative, zero, or positive according to the lexical row order.
     */
    private static function compareRows(string $left, string $right): int
    {
        return strcmp($left, $right);
    }

    /**
     * Serialize a row through first-class callable syntax.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private static function serialiseRow(string $promptLabel): string
    {
        return trim($promptLabel);
    }

    /**
     * Format a row referenced through a `$this` callable target.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private function formatThisRow(string $promptLabel): string
    {
        return trim($promptLabel);
    }

    /**
     * Format a row referenced through a case-insensitive `self::class` method slot.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    public static function formatSelfRow(string $promptLabel): string
    {
        return trim($promptLabel);
    }

    /**
     * Format a row referenced through a `static::class` callable target.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private static function formatStaticRow(string $promptLabel): string
    {
        return trim($promptLabel);
    }

    /**
     * Format a row referenced through the fully resolved declaring class.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private static function formatClassRow(string $promptLabel): string
    {
        return trim($promptLabel);
    }

    /**
     * Format a row referenced through PHP's `__CLASS__` token.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private static function formatMagicRow(string $promptLabel): string
    {
        return trim($promptLabel);
    }
}

/**
 * Holds callback-like shapes that static analysis must not claim as exact references.
 * Each local candidate shares a method name with an unsupported or foreign target.
 * Users can keep these methods via the explicit symbol allowlist when runtime wiring is intentional.
 */
final class ConservativeCallbackBoundaryFixture
{
    /**
     * Invoke conservative callback shapes without proving any local target declaration.
     *
     * @param list<string> $rows - Values supplied to each callback; an empty list produces an empty result.
     * @param object $dynamicReceiver - Runtime callback owner whose class cannot be proven locally.
     * @param string $computedMethod - Runtime method slot; an empty value is invalid but still unprovable statically.
     *
     * @return list<string> - Combined runtime callback results; empty when no rows were supplied.
     */
    public function callbackRows(array $rows, object $dynamicReceiver, string $computedMethod): array
    {
        $fromForeign  = array_map([ForeignCallbackProvider::class, 'foreignClassTarget'], $rows);
        $fromDynamic  = array_map([$dynamicReceiver, 'dynamicReceiverTarget'], $rows);
        $fromComputed = array_map([self::class, $computedMethod], $rows);
        $fromMissing  = array_map([MissingCallbackProvider::class, 'missingClassTarget'], $rows);
        $fromCollision = array_map([
            \Fixtures\DeadCode\CallbackForeign\ConservativeCallbackBoundaryFixture::class,
            'shortNameCollisionTarget',
        ], $rows);
        $stringCallbackRows = array_map(
            'Fixtures\\DeadCode\\ConservativeCallbackBoundaryFixture::stringCallbackTarget',
            $rows,
        );

        return [
            ...$fromForeign,
            ...$fromDynamic,
            ...$fromComputed,
            ...$fromMissing,
            ...$fromCollision,
            ...$stringCallbackRows,
        ];
    }

    /**
     * Stay visible when only a foreign class has a same-named callback.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private static function foreignClassTarget(string $promptLabel): string
    {
        return trim($promptLabel);
    }

    /**
     * Stay visible when the callback receiver is computed at runtime.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private static function dynamicReceiverTarget(string $promptLabel): string
    {
        return trim($promptLabel);
    }

    /**
     * Stay visible when the callback method slot is computed at runtime.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private static function computedMethodTarget(string $promptLabel): string
    {
        return trim($promptLabel);
    }

    /**
     * Stay visible when the callback class has no declaration in the parsed unit.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private static function missingClassTarget(string $promptLabel): string
    {
        return trim($promptLabel);
    }

    /**
     * Stay visible when another namespace contains the same short class name.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private static function shortNameCollisionTarget(string $promptLabel): string
    {
        return trim($promptLabel);
    }

    /**
     * Stay visible because string callables require an explicit symbol allowlist.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    private static function stringCallbackTarget(string $promptLabel): string
    {
        return trim($promptLabel);
    }
}

/**
 * Provides a valid foreign callback with the same method name as a local candidate.
 * The fixture proves that method names alone never identify callback declarations.
 * Users see only the local wrapper finding because this provider owns multiple statements.
 */
final class ForeignCallbackProvider
{
    /**
     * Format the foreign callback value without becoming a one-line wrapper candidate.
     *
     * @param string $promptLabel - Raw prompt label; an empty string stays empty through both operations.
     *
     * @return string - Uppercase trimmed value; empty when the row contains only whitespace.
     */
    public static function foreignClassTarget(string $promptLabel): string
    {
        $trimmed = trim($promptLabel);

        return strtoupper($trimmed);
    }
}

/**
 * Declares a callback method that a child later references through the child's class name.
 * The declaration owner deliberately differs from that callback target's resolved class.
 * Users must allowlist this conservative inherited shape when the runtime callback is intentional.
 */
class ParentDeclaredCallbackBoundaryFixture
{
    /**
     * Stay visible when a child class name, rather than this owner, forms the callback.
     *
     * @param string $promptLabel - Raw prompt label; an empty string remains empty.
     *
     * @return string - Trimmed row value; empty when the row contains only whitespace.
     */
    protected static function parentDeclaredTarget(string $promptLabel): string
    {
        return trim($promptLabel);
    }
}

/**
 * References an inherited method through the child class name for conservative matching.
 * The callback is valid at runtime but does not identify the parent's exact declaration.
 * Users reach this edge case when inherited callback registration uses late class names.
 */
final class ChildCallbackBoundaryFixture extends ParentDeclaredCallbackBoundaryFixture
{
    /**
     * Invoke the inherited callback using the child as its resolved target class.
     *
     * @param list<string> $rows - Values supplied to the inherited callback; an empty list stays empty.
     *
     * @return list<string> - Trimmed row values; empty when no rows were supplied.
     */
    public static function callbackRows(array $rows): array
    {
        $mappedRows = array_map([self::class, 'parentDeclaredTarget'], $rows);

        return array_values($mappedRows);
    }
}

final class DataCapabilities
{
    public static function rows(): array
    {
        return [];
    }
}

namespace Fixtures\DeadCode\CallbackForeign;

/**
 * Supplies the same short class name as the conservative fixture in another namespace.
 * Its valid callback proves that fully resolved class identity must survive indexing.
 * Users avoid suppressing a local wrapper merely because this foreign provider shares its name.
 */
final class ConservativeCallbackBoundaryFixture
{
    /**
     * Format the foreign callback value without becoming a one-line wrapper candidate.
     *
     * @param string $promptLabel - Raw prompt label; an empty string stays empty through both operations.
     *
     * @return string - Uppercase trimmed value; empty when the row contains only whitespace.
     */
    public static function shortNameCollisionTarget(string $promptLabel): string
    {
        $trimmed = trim($promptLabel);

        return strtoupper($trimmed);
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
