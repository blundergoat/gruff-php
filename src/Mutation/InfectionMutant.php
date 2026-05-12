<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

final readonly class InfectionMutant
{
    /**
     * Capture a mutant entry parsed from Infection output.
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
