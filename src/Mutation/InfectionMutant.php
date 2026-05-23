<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

/**
 * Represents one mutant entry parsed from an Infection report.
 */
final readonly class InfectionMutant
{
    /**
     * Capture a mutant entry parsed from Infection output.
     *
     * @param string      $status        Infection status for the mutant.
     * @param string      $filePath      Source file path reported by Infection.
     * @param int|null    $line          Source line reported for the mutant, when available.
     * @param string      $mutator       Mutator name that produced the mutant.
     * @param string|null $diff          Mutant diff text, when Infection provided it.
     * @param string|null $processOutput Per-mutant process output, when available.
     */
    public function __construct(
        public string $status,
        public string $filePath,
        public ?int $line,
        public string $mutator,
        public ?string $diff = null,
        public ?string $processOutput = null,
    ) {
    }

    /**
     * Serialize this value object into the array shape used by reports.
     *
     * @return array{status: string, file: string, line: int|null, mutator: string, diff: string|null, processOutput: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'file' => $this->filePath,
            'line' => $this->line,
            'mutator' => $this->mutator,
            'diff' => $this->diff,
            'processOutput' => $this->processOutput,
        ];
    }
}
