<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * Immutable bundle of the three baseline decisions for a single `gruff-php analyse` run: whether
 * to apply an existing baseline, whether that baseline was named by the user or picked up as the
 * project default, and whether to write a fresh baseline instead. The command layer fills this in
 * from the user's `--baseline`, `--generate-baseline`, and `--no-baseline` flags, then hands it to
 * `BaselineApplication`. That reader decides whether the run suppresses already-accepted findings,
 * records the current findings as accepted debt, or shows every finding untouched.
 */
final readonly class BaselineApplicationOptions
{
    /**
     * Freezes the baseline choices for one analyse run, built while parsing the user's command-line
     * flags and read later when the run decides how to treat findings it has seen before.
     *
     * @param string|null $baselinePath - Path of the baseline file whose recorded findings this run suppresses; null when no baseline applies (a plain run or `--no-baseline`), so every finding is shown.
     * @param bool        $isBaselineExplicit - True when the user typed `--baseline` themselves, false when the path is the auto-adopted project default; only decides whether the baseline report is labelled explicit or default.
     * @param string|null $generateBaselinePath - Path to write a fresh baseline to, recording the current findings as accepted debt; null on a normal run that writes nothing, and any path here takes precedence over applying a baseline.
     */
    public function __construct(
        public ?string $baselinePath,
        public bool $isBaselineExplicit,
        public ?string $generateBaselinePath,
    ) {
    }
}
