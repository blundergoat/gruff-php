<?php

declare(strict_types=1);

namespace GruffPhp\Review;

use GruffPhp\Finding\Finding;

/**
 * Carries introduced, resolved, and existing findings for branch review.
 */
final readonly class BranchReviewResult
{
    /**
     * @param list<Finding> $introduced
     * @param list<Finding> $removed
     * @param list<Finding> $unchanged
     */
    public function __construct(
        public string $base,
        public bool $changedOnly,
        public array $introduced,
        public array $removed,
        public array $unchanged,
        public ?float $deltaScore,
    ) {
    }

    /**
     * @param callable(list<Finding>): list<Finding> $filter
     * @return self Review result with the same metadata and filtered finding groups.
     */
    public function filtered(callable $filter): self
    {
        return new self(
            base: $this->base,
            changedOnly: $this->changedOnly,
            introduced: $filter($this->introduced),
            removed: $filter($this->removed),
            unchanged: $filter($this->unchanged),
            deltaScore: $this->deltaScore,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'active' => true,
            'base' => $this->base,
            'changedOnly' => $this->changedOnly,
            'counts' => [
                'introduced' => count($this->introduced),
                'removed' => count($this->removed),
                'unchanged' => count($this->unchanged),
            ],
            'deltaScore' => $this->deltaScore,
            'introduced' => array_map(static fn (Finding $finding): array => $finding->toArray(), $this->introduced),
            'removed' => array_map(static fn (Finding $finding): array => $finding->toArray(), $this->removed),
            'unchanged' => array_map(static fn (Finding $finding): array => $finding->toArray(), $this->unchanged),
        ];
    }
}
