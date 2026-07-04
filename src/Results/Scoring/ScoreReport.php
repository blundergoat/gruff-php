<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

/**
 * The complete scoring verdict for one run - the composite grade plus every breakdown a user drills
 * into: per-pillar grades, the worst files, and the complexity spread.
 *
 * This is the object every reporter renders when it shows a score: the headline composite letter, the
 * pillar table, the top file offenders, and the histogram of how complex the code is. Whatever surface
 * the user is on - terminal, JSON, the HTML dashboard - reads its numbers from here, so the grade they
 * see is the same everywhere.
 */
final readonly class ScoreReport
{
    /**
     * Bundles the composite grade with the pillar, file, and distribution breakdowns a report renders.
     *
     * @param Grade              $composite - Overall letter grade and score for the whole run - the headline figure.
     * @param list<PillarScore>  $pillars - Per-pillar scores that make up the breakdown table.
     * @param list<FileScore>    $topOffenders - Lowest-scoring files, worst first, for the "fix these first" list; empty when nothing scored.
     * @param array<string, int> $complexityDistribution - Method counts per cyclomatic-complexity bucket, for the histogram.
     * @param string             $scope - Which code the score covers (for example whole-project or a diff), so the user knows what was graded.
     * @param string             $explanation - Plain-English note on how the score was reached, shown under the headline.
     */
    public function __construct(
        public Grade  $composite,
        public array  $pillars,
        public array  $topOffenders,
        public array  $complexityDistribution,
        public string $scope,
        public string $explanation,
    ) {
    }

    /**
     * Flattens the whole verdict into the JSON shape reporters serialise, so an editor, CI gate, or the
     * dashboard reads the same grade a person sees in the terminal.
     *
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
     * } - JSON-serialisable snapshot of the full report; nested value objects rendered via their own toArray, distribution keyed by complexity bucket.
     */
    public function toArray(): array
    {
        return [
            'composite'              => $this->composite->toArray(),
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
