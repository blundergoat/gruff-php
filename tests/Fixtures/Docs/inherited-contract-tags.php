<?php

declare(strict_types=1);

/**
 * A documented ancestor in this file owns the contract for AuditedRepository::save().
 */
abstract class DocumentedRepository
{
    /**
     * Persist one record.
     *
     * @param array<string, mixed> $record Row to store.
     *
     * @return int The new row id.
     */
    abstract public function save(array $record): int;
}

/**
 * Every method here but rename() inherits a contract declared by an interface or parent.
 */
final class AuditedRepository extends DocumentedRepository implements ExternalRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function find(int $recordId): array
    {
        return [$recordId];
    }

    /**
     * @inheritDoc
     */
    public function count(string $table): int
    {
        return strlen($table);
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $key): bool
    {
        return $key !== '';
    }

    /**
     * Keeps the parent's signature.
     */
    #[\Override]
    public function purge(string $reason): bool
    {
        return $reason !== '';
    }

    /**
     * Stores the record with an audit stamp.
     */
    public function save(array $record): int
    {
        return count($record);
    }

    /**
     * Rename one record, a method no ancestor declares.
     */
    public function rename(int $recordId, string $name): string
    {
        return $name . $recordId;
    }
}

/**
 * A parent whose constructor documents its own parameter.
 */
abstract class DocumentedService
{
    /**
     * Build the service.
     *
     * @param int $retryLimit How many times to retry.
     */
    public function __construct(protected int $retryLimit)
    {
    }
}

/**
 * PHP inherits no constructor signature, so a child constructor owes every tag, promoted or not.
 */
final class PromotingService extends DocumentedService
{
    /**
     * Build the service with a name.
     */
    public function __construct(int $retryLimit, private string $serviceName)
    {
        parent::__construct($retryLimit);
    }
}

/**
 * Widens the parent's save() with a flag the parent never declared, so that flag alone is owed here.
 */
final class WideningRepository extends DocumentedRepository
{
    /**
     * Stores the record and optionally audits it.
     */
    public function save(array $record, bool $audit = false): int
    {
        return $audit ? count($record) : 0;
    }
}

/**
 * A parent whose method docblock is prose with no tags.
 */
abstract class ProseOnlyRepository
{
    /**
     * Remove one record.
     */
    abstract public function remove(int $recordId): bool;
}

/**
 * Overrides a method whose visible contract declares no tags, so both tags are owed here as well.
 */
final class ProseOnlyChild extends ProseOnlyRepository
{
    /**
     * Removes one record by id.
     */
    public function remove(int $recordId): bool
    {
        return $recordId > 0;
    }
}

/**
 * A class with no ancestor, so none of its methods can inherit a contract.
 */
final class StandaloneRepository
{
    /**
     * Counts rows; this method is new, so it does not use @inheritdoc.
     */
    public function tally(int $limit): int
    {
        return $limit;
    }

    /**
     * {@inheritdoc}
     */
    public function lookup(int $recordId): array
    {
        return [$recordId];
    }
}

/**
 * A parent whose only run() is private, so a child's run() overrides nothing.
 */
class PrivateRunner
{
    /**
     * Run privately.
     *
     * @param int $limit Upper bound.
     *
     * @return int The limit.
     */
    private function run(int $limit): int
    {
        return $limit;
    }
}

/**
 * Declares its own run(), which owes both tags.
 */
final class PublicRunner extends PrivateRunner
{
    /**
     * Run publicly.
     */
    public function run(int $limit): int
    {
        return $limit;
    }
}

/**
 * Documents only its first parameter.
 */
abstract class HalfDocumentedPair
{
    /**
     * Pair two values.
     *
     * @param int $left The first value.
     */
    abstract public function pair(int $left, int $right): int;
}

/**
 * Swaps the parameter names, so each is matched by name: $left inherits its tag and $right owes one.
 */
final class SwappedPair extends HalfDocumentedPair
{
    /**
     * Pairs the values.
     */
    public function pair(int $right, int $left): int
    {
        return $left - $right;
    }
}

/**
 * Refines a contract declared in another file, so its marker is honoured.
 */
interface RefinedIterator extends \Iterator
{
    /**
     * {@inheritdoc}
     */
    public function key(): mixed;
}
