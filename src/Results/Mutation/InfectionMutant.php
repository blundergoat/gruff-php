<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * Represents one mutant parsed from a recognised status section of an Infection JSON report.
 * The record stores the mutation's location, mutator, status, and optional diagnostic output.
 *
 * `--infection-report` feeds these records into mutation analysis.
 * Escaped and timed-out mutants become findings whose wording depends on the status and mutator.
 */
final readonly class InfectionMutant
{
    /**
     * Stores the parser's normalised mutant record for mutation reporting.
     *
     * @param string $status - Normalised status; `escaped` and `timed out` records become survived-mutant findings.
     * @param string $filePath - Display path of the mutated source file.
     * @param int|null $line - Start line of the mutation; null leaves the finding without a line location.
     * @param string $mutator - Mutation name included in survived-mutant messages.
     * @param string|null $diff - Mutation diff; the parser maps missing, empty, or non-string input to null.
     * @param string|null $processOutput - Per-mutant test output; the parser maps missing, empty, or non-string input to null.
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
     * Exports one mutant using gruff's report keys without transforming its stored values.
     * Null optional fields remain null.
     *
     * @return array{status: string, file: string, line: int|null, mutator: string, diff: string|null, processOutput: string|null} - Values unchanged.
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
