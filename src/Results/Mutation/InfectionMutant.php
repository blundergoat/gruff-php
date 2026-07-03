<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * Represents one mutant entry parsed from an Infection report.
 */
final readonly class InfectionMutant
{
    /**
     * Capture a mutant entry parsed from Infection output.
     *
      * User flow: Folds mutation results into the quality feedback users see.
      *
     * @param string      $status - Infection status for the mutant.
     * @param string      $filePath - Source file path reported by Infection.
     * @param int|null    $line - Source line reported for the mutant, when available.
     * @param string      $mutator - Mutator name that produced the mutant.
     * @param string|null $diff - Mutant diff text, when Infection provided it.
     * @param string|null $processOutput - Per-mutant process output, when available.
     */
    public function __construct(
        public string  $status,
        public string  $filePath,
        public ?int    $line,
        public string  $mutator,
        public ?string $diff = null,
        public ?string $processOutput = null,
    ) {
    }

    /**
     * Serialize this value object into the array shape used by reports.
     *
      * User flow: Folds mutation results into the quality feedback users see.
      *
     * @return array{status: string, file: string, line: int|null, mutator: string, diff: string|null, processOutput: string|null} - report-ready map
     *                       keyed by Infection JSON field names; line/diff/processOutput are null when Infection omitted them
     */
    public function toArray(): array
    {
        return [
            'status'        => $this->status,
            'file'          => $this->filePath,
            'line'          => $this->line,
            'mutator'       => $this->mutator,
            'diff'          => $this->diff,
            'processOutput' => $this->processOutput,
        ];
    }
}
