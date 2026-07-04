<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

/**
 * One quality pillar's line in the score breakdown - its grade, its finding counts by severity, and
 * the penalty those findings cost.
 *
 * The score report grades each pillar (naming, security, complexity, ...) on its own, so a user can
 * see exactly where their code is strong and where it slips. This readonly row carries that per-pillar
 * verdict: whether the pillar even applied to this run, its letter grade and score when it did, how
 * many findings of each severity landed against it, and the penalty they added up to.
 */
final readonly class PillarScore
{
    /**
     * Captures one pillar's grade, severity tallies, and penalty for the score breakdown.
     *
     * @param string     $pillar - Pillar identifier this row scores (for example 'security' or 'naming').
     * @param bool       $applicable - False when no rule in this pillar applied to the run, so the pillar is listed but not graded.
     * @param Grade|null $grade - Letter grade and score for the pillar; null when the pillar was inapplicable, so the report shows "n/a" rather than a misleading zero.
     * @param int        $findings - Total findings counted against the pillar.
     * @param int        $advisory - Advisory-severity findings in the pillar.
     * @param int        $warning - Warning-severity findings in the pillar.
     * @param int        $error - Error-severity findings in the pillar.
     * @param float      $penalty - Total score penalty these findings cost the pillar.
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
     * } - report-ready snapshot of this pillar; score and grade are null when the pillar is inapplicable, and penalty is rounded to 2 decimals to
     * match report precision.
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
