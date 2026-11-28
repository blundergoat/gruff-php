<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

/**
 * One quality pillar's score-report row, carrying applicability, an optional grade, severity counts, and penalty.
 * Static pillars use finding weights; the mutation pillar uses the mutation score.
 *
 * An applicable static pillar can remain ungraded when no files were evaluated.
 */
final readonly class PillarScore
{
    /**
     * Captures one pillar's grade, severity tallies, and penalty for the score breakdown.
     *
     * @param string     $pillar - Pillar identifier this row scores (for example 'security' or 'naming').
     * @param bool       $applicable - Whether this pillar applies; false when an included mutation pillar lacks a report.
     * @param Grade|null $grade - Optional grade; null for an inapplicable pillar or a static pillar with no evaluated files.
     * @param int        $findings - Total findings counted against the pillar.
     * @param int        $advisory - Advisory-severity findings in the pillar.
     * @param int        $warning - Warning-severity findings in the pillar.
     * @param int        $error - Error-severity findings in the pillar.
     * @param float      $penalty - Summed finding weights for static analysis, or the nonnegative MSI shortfall from 100 for mutation.
     */
    public function __construct(
        public string $pillar,
        public bool   $applicable,
        public ?Grade $grade,
        public int    $findings,
        public int    $advisory,
        public int    $warning,
        public int    $error,
        public float  $penalty,
    ) {
    }

    /**
     * Flattens the pillar row into the JSON shape the score report serialises for editors and CI.
     *
     * @return array{
     *     pillar: string,
     *     applicable: bool,
     *     score: float|null,
     *     grade: string|null,
     *     findings: int,
     *     advisory: int,
     *     warning: int,
     *     error: int,
     *     penalty: float
     * } - Report row; an inapplicable pillar has null score and grade, and penalty is rounded to two decimals.
     */
    public function toArray(): array
    {
        return [
            'pillar'     => $this->pillar,
            'applicable' => $this->applicable,
            'score'      => $this->grade?->score,
            'grade'      => $this->grade?->letter,
            'findings'   => $this->findings,
            'advisory'   => $this->advisory,
            'warning'    => $this->warning,
            'error'      => $this->error,
            'penalty'    => round($this->penalty, 2),
        ];
    }
}
