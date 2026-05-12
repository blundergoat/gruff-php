<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

use GruffPhp\Analysis\RunDiagnostic;

final readonly class InfectionRunResult
{
    /**
     * Capture process output and optional diagnostic from an Infection run.
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public ?RunDiagnostic $diagnostic = null,
    ) {
    }
}
