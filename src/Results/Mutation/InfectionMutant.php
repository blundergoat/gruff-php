<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * One mutant lifted from an Infection JSON report: a single deliberate edit Infection made to
 * the source, paired with the verdict on whether the test suite caught it.
 *
 * A user reaches these records by handing gruff-php an Infection report - for example
 * `gruff-php analyse --infection-report=infection.log.json` - which parses every mutant row
 * into one of these read-only objects. Survivors (mutants no test killed) later become the
 * findings the user acts on, and the `status` and `mutator` held here pick the exact wording.
 */
final readonly class InfectionMutant
{
    /**
     * Holds one parsed mutant exactly as Infection reported it, so the rest of the run can decide
     * whether it survived and, if so, what to tell the user about the tests guarding that code.
     *
     * @param string      $status - Normalised Infection verdict such as `killed`, `escaped`, `timed out`, or `not covered`; the survivors are the ones that turn into findings for the user.
     * @param string      $filePath - Display path of the mutated source, so the user sees which file the weak or missing test was meant to guard.
     * @param int|null    $line - Start line of the mutated code; null when Infection reported no line, so the finding names the file without pointing the user at a specific row.
     * @param string      $mutator - Name of the mutator that made the edit (for example the operator it flipped); echoed back to the user in the survived-mutation message.
     * @param string|null $diff - Before/after diff of what the mutant changed; null when Infection omitted it or sent a blank string, leaving the user no inline change to inspect.
     * @param string|null $processOutput - Test-run output captured for this one mutant; null when Infection omitted it or sent a blank string, so there is no per-mutant log for the user to open.
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
     * Flattens the mutant into gruff's own flat `status`/`file`/`line`/`mutator`/`diff`/`processOutput` map
     * for the report's machine-readable output - a script or editor gets one tidy row per mutant, not Infection's nested shape.
     *
     * @return array{status: string, file: string, line: int|null, mutator: string, diff: string|null, processOutput: string|null} - report-ready flat map
     *                       using gruff's own keys (not Infection's nested shape); `line`, `diff`, and `processOutput` come back null when Infection omitted them, so the reader gets no row number, no diff, and no per-mutant log
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
