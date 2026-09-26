<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

/**
 * The current run's scoring context, as the branch review needs it to state a delta honestly.
 *
 * The two values travel together because a delta is only meaningful when both sides were scored the
 * same way: the ratified formula divides weighted findings by the evaluated-file count, so a base
 * snapshot scored against its own file count would report movement that is really a size difference.
 */
final readonly class ReviewScoreContext
{
    /**
     * Pairs the current composite with the denominator it was produced from.
     *
     * @param float|null $currentScore - Composite for the current run, or null when it evaluated nothing and has no score.
     * @param int        $evaluatedFiles - Ratified denominator for the current run, reused to score the base side.
     */
    public function __construct(
        public ?float $currentScore,
        public int    $evaluatedFiles,
    ) {
    }
}
