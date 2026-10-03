<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

/**
 * The audit block a hook consumer needs before it trusts a verdict: what ran, over what, and against which baseline.
 *
 * Without it a clean payload is ambiguous, because a run that analysed nothing and a run that found nothing look
 * identical on the wire. The block is deliberately about the run rather than the findings, so a consumer can tell an
 * empty result caused by a narrow scope from one caused by clean code.
 */
final readonly class HookRunAudit
{
    /**
     * Captures what one hook run actually did, for the `run` block of the `gruff.hook.v2` payload.
     *
     * @param string       $mode          - Which region selector chose the work: `changed-ranges`, `diff`, `since`, or `full`.
     * @param string       $scope         - How wide each changed line was taken to be: `symbol`, `hunk`, or `file`.
     * @param list<string> $paths         - The operands as the caller gave them; empty when the caller named none.
     * @param int          $analysedFiles - How many files were actually analysed; zero is reported, never hidden.
     * @param bool         $isBaselineApplied - Whether a baseline classified this run's findings.
     * @param string|null  $baselinePath  - Project-relative baseline the caller named; null when none was applied.
     */
    public function __construct(
        public string  $mode,
        public string  $scope,
        public array   $paths,
        public int     $analysedFiles,
        public bool    $isBaselineApplied = false,
        public ?string $baselinePath = null,
    ) {
    }

    /**
     * Flattens the audit into the JSON shape the `run` block promises, naming the baseline schema only when one applied.
     *
     * @param string $baselineSchemaVersion - Schema every applied baseline carries, so a suppressed finding is explicable.
     *
     * @return array<string, mixed> - The `run` block: mode, scope, operands, analysed-file count, and the baseline that classified the run.
     */
    public function toArray(string $baselineSchemaVersion): array
    {
        return [
            'mode'          => $this->mode,
            'scope'         => $this->scope,
            'paths'         => $this->paths,
            'analysedFiles' => $this->analysedFiles,
            'baseline'      => [
                'applied'       => $this->isBaselineApplied,
                'schemaVersion' => $this->isBaselineApplied ? $baselineSchemaVersion : null,
                'path'          => $this->isBaselineApplied ? $this->baselinePath : null,
            ],
        ];
    }
}
