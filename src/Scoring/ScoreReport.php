<?php

declare(strict_types=1);

namespace GruffPhp\Scoring;

/**
 * Carries composite, pillar, file, and distribution scoring output.
 */
final readonly class ScoreReport
{
    /**
     * @param list<PillarScore> $pillars
     * @param list<FileScore> $topOffenders
     * @param array<string, int> $complexityDistribution
     */
    public function __construct(
        public Grade $composite,
        public array $pillars,
        public array $topOffenders,
        public array $complexityDistribution,
        public string $scope,
        public string $explanation,
    ) {
    }

    /**
     * @return array{
     *     composite: array{score: float, grade: string},
     *     pillars: list<array{
     *         pillar: string,
     *         applicable: bool,
     *         score: float|null,
     *         grade: string|null,
     *         findings: int,
     *         advisories: int,
     *         warnings: int,
     *         errors: int,
     *         penalty: float
     *     }>,
     *     topOffenders: list<array{
     *         file: string,
     *         score: float,
     *         grade: string,
     *         findings: int,
     *         advisories: int,
     *         warnings: int,
     *         errors: int,
     *         penalty: float,
     *         maxCyclomatic: int|null,
     *         maxCognitive: int|null,
     *         maxLines: int|null,
     *         mutationScore: float|null
     *     }>,
     *     complexityDistribution: array<string, int>,
     *     scope: string,
     *     explanation: string
     * }
     */
    public function toArray(): array
    {
        return [
            'composite' => $this->composite->toArray(),
            'pillars' => array_map(
                static fn (PillarScore $pillar): array => $pillar->toArray(),
                $this->pillars,
            ),
            'topOffenders' => array_map(
                static fn (FileScore $file): array => $file->toArray(),
                $this->topOffenders,
            ),
            'complexityDistribution' => $this->complexityDistribution,
            'scope' => $this->scope,
            'explanation' => $this->explanation,
        ];
    }
}
