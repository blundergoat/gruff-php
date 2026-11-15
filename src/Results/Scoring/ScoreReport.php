<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

/**
 * Holds the composite grade, pillar and file summaries, and scoring attribution for one analysis run.
 * Reporters can serialize the stored values with toArray(); an absent composite becomes null score and grade.
 * The complexity distribution counts contributing cyclomatic findings rather than every method.
 */
final readonly class ScoreReport
{
    /**
     * Bundles the composite grade with the pillar, file, and distribution breakdowns a report renders.
     *
     * @param Grade|null $composite - Composite grade, or null when no applicable pillar has a grade.
     * @param list<array{file: string, symbol: string, ruleIds: list<string>, findings: int, weight: float}> $clusters - Groups sharing one weight.
     * @param list<array{ruleId: string, findings: int, weight: float}> $ruleAttribution - Finding counts and weights by native rule ID.
     * @param int $evaluatedFiles - Number of parsed PHP files used as the static scoring denominator.
     * @param list<string> $scoredPillars - Resolved pillar names, including any pillar without a grade.
     * @param list<PillarScore> $pillars - Per-pillar scores that make up the breakdown table.
     * @param list<FileScore> $topOffenders - Worst grades first, then more findings and ascending path; ungraded files may appear.
     * @param array<string, int> $complexityDistribution - Counts of scored cyclomatic findings by range label, not all methods.
     * @param string $scope - Which code the score covers, such as the full project or a diff.
     * @param string $explanation - Plain-English note on how the score was reached, shown under the headline.
     */
    public function __construct(
        public ?Grade $composite,
        public array  $clusters,
        public array  $ruleAttribution,
        public int    $evaluatedFiles,
        public array  $scoredPillars,
        public array  $pillars,
        public array  $topOffenders,
        public array  $complexityDistribution,
        public string $scope,
        public string $explanation,
    ) {
    }

    /**
     * Flattens the score report into an array, preserving null composite fields and converting nested score objects.
     *
     * @return array{
     *     composite: array{score: float|null, grade: string|null},
     *     clusters: list<array{file: string, symbol: string, ruleIds: list<string>, findings: int, weight: float}>,
     *     ruleAttribution: list<array{ruleId: string, findings: int, weight: float}>,
     *     evaluatedFiles: int,
     *     scoredPillars: list<string>,
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
     *         score: float|null,
     *         grade: string|null,
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
     * } - Full report snapshot; nested values use their own toArray and the distribution is keyed by complexity bucket.
     */
    public function toArray(): array
    {
        return [
            'composite'              => $this->composite?->toArray() ?? ['score' => null, 'grade' => null],
            'clusters'               => $this->clusters,
            'ruleAttribution'        => $this->ruleAttribution,
            'evaluatedFiles'         => $this->evaluatedFiles,
            'scoredPillars'          => $this->scoredPillars,
            'pillars'                => array_map(
                static fn(PillarScore $pillar): array => $pillar->toArray(),
                $this->pillars,
            ),
            'topOffenders'           => array_map(
                static fn(FileScore $file): array => $file->toArray(),
                $this->topOffenders,
            ),
            'complexityDistribution' => $this->complexityDistribution,
            'scope'                  => $this->scope,
            'explanation'            => $this->explanation,
        ];
    }
}
