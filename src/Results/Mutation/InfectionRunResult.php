<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Engine\Analysis\RunDiagnostic;

/**
 * Captures the exit status and output of an Infection invocation for the mutation builder.
 * The runner supplies a diagnostic when executable resolution fails before a process is started.
 *
 * A result without a diagnostic still needs a fresh, parseable report before analysis can continue.
 */
final readonly class InfectionRunResult
{
    /**
     * Stores the runner's captured outcome and optional executable-resolution diagnostic.
     *
     * @param int $exitCode - Process exit status, or 2 for an unresolved executable or absent exit status.
     * @param string $output - Captured standard output; empty when none was captured.
     * @param string $errorOutput - Captured standard error; empty when none was captured.
     * @param RunDiagnostic|null $diagnostic - Runner failure diagnostic; null leaves report freshness checking to the builder.
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public ?RunDiagnostic $diagnostic = null,
    ) {
    }
}
