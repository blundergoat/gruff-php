<?php

declare(strict_types=1);

namespace GruffPhp\Scoring;

/**
 * Carries composite, pillar, file, and distribution scoring output.
 */
final readonly class ScoreReport
{
    /**
     * @param Grade              $composite              Overall grade for the analysis run.
     * @param list<PillarScore>  $pillars                Pillar scores included in the report.
     * @param list<FileScore>    $topOffenders           Lowest-scoring files shown in reports.
     * @param array<string, int> $complexityDistribution Cyclomatic complexity buckets.
     * @param string             $scope                  Score scope description.
     * @param string             $explanation            Human-readable scoring explanation.
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
     *         advisory: int,
     *         warning: int,
     *         error: int,
     *         penalty: float
     *     }>,
     *     topOffenders: list<array{
     *         file: string,
     *         score: float,
     *         grade: string,
     *         findings: int,
     *         advisory: int,
     *         warning: int,
     *         error: int,
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
        // Each nested value object owns its own wire shape, so delegate rather than rebuild the schema here.
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
