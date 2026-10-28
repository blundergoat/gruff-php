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
     * @param Grade|null         $composite - Overall letter grade and score for the whole run - the headline figure, null when nothing
     *                                      applicable was evaluated and there is no health to report.
     * @param list<array{file: string, symbol: string, ruleIds: list<string>, findings: int, weight: float}> $clusters - Correlated concepts
     *                                           that billed one shared weight, so a reader can see which findings the grade counted once.
     * @param list<array{ruleId: string, findings: int, weight: float}> $ruleAttribution - How much weight each native rule removed from the
     *                                           score; the native ruleId is the ratified attribution key.
     * @param int                $evaluatedFiles - Ratified scoring denominator: PHP files that survived discovery and parsed. Published so a
     *                                           reader can reproduce the composite without guessing which file count it used.
     * @param list<string>       $scoredPillars - Every pillar the run could reach, so the composite's denominator is visible rather than inferred.
     * @param list<PillarScore>  $pillars - Per-pillar scores that make up the breakdown table.
     * @param list<FileScore>    $topOffenders - Lowest-scoring files, worst first, for the "fix these first" list; empty when nothing scored.
     * @param array<string, int> $complexityDistribution - Method counts per cyclomatic-complexity bucket, for the histogram.
     * @param string             $scope - Which code the score covers (for example whole-project or a diff), so the user knows what was graded.
     * @param string             $explanation - Plain-English note on how the score was reached, shown under the headline.
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
     * Flattens the whole verdict into the JSON shape reporters serialise, so an editor, CI gate, or the
     * dashboard reads the same grade a person sees in the terminal.
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
     * } - JSON-serialisable snapshot of the full report; nested value objects rendered via their own toArray, distribution keyed by complexity bucket.
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
