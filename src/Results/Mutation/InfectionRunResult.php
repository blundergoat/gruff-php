<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Engine\Analysis\RunDiagnostic;

/**
 * The single handoff between running Infection and turning its output into mutation feedback.
 *
 * `InfectionRunner` shells out to Infection for `gruff-php analyse --infection-run` and packs
 * everything that invocation produced into this readonly value: the process exit code, its
 * captured stdout and stderr, and an optional diagnostic. `MutationAnalysisBuilder` reads its exit
 * code and diagnostic the moment the run returns to decide what the user sees - go on to read the
 * on-disk mutation report, or show a "could not run Infection" note when the tool never started. Reach for it whenever
 * mutation analysis needs the raw outcome of one Infection run gathered in one place.
 */
final readonly class InfectionRunResult
{
    /**
     * Bundles one Infection run's outcome so the mutation builder can tell a real run from one that never
     * started - it reads this object's exit code and diagnostic, then parses the on-disk report for scores. Filled by the runner both when Infection finished and when it never
     * started - for example when `gruff-php analyse --infection-run` can't resolve the `--infection-bin` executable.
     *
     * @param int                $exitCode - Exit status Infection returned; `0` when the run passed the configured thresholds, non-zero when it failed or errored, and `2` as the stand-in when the process reported none.
     * @param string             $output - Captured stdout, retained for diagnostics only - no consumer parses it (Infection runs with `--log-verbosity=none`, and the per-file report is read from the on-disk JSON); empty when the run produced no output.
     * @param string             $errorOutput - Captured stderr; empty on a clean run, and carrying Infection's failure text when the run went wrong.
     * @param RunDiagnostic|null $diagnostic - Set only when the run could not complete normally (say a missing Infection binary); null means Infection actually ran, so the builder goes on to read the on-disk report (which may still fail its freshness gate and yield a `mutation-run-error`) rather than surfacing a start-up error here.
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public ?RunDiagnostic $diagnostic = null,
    ) {
    }
}
