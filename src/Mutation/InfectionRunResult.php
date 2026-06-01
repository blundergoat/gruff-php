<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

use GruffPhp\Analysis\RunDiagnostic;

/**
 * Captures the process result and report path from an Infection run.
 */
final readonly class InfectionRunResult
{
    /**
     * Capture process output and optional diagnostic from an Infection run.
     *
     * @param int                $exitCode - Infection process exit code.
     * @param string             $output - Captured standard output.
     * @param string             $errorOutput - Captured standard error output.
     * @param RunDiagnostic|null $diagnostic - Diagnostic emitted when the run could not complete normally.
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public ?RunDiagnostic $diagnostic = null,
    ) {
    }
}
