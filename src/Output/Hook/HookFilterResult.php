<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Finding\Finding;

/**
 * Carries the outcome of hook finding-filtering back to the `hook` command that assembles the
 * agent feedback payload. When an editor or coding agent runs `gruff-php hook` after touching a
 * file, the filter either narrows a changed-region pass down to the findings on the edited lines
 * (with `--diff`/`--since`/`--changed-ranges`/`--baseline`), or - on a plain full scan - passes
 * every finding through. It hands back this object: the kept findings the agent is shown, a count
 * of any hidden for sitting outside the change or already existing, and the identity map the
 * command uses to stamp each kept finding's stable id.
 */
final readonly class HookFilterResult
{
    /**
     * Bundles the three filtering outputs the `hook` command needs to build its payload: what to
     * show the agent, how much was held back, and how to label each finding.
     *
     * @param list<Finding>      $findings        - Findings that survived filtering and get shown to the agent; empty means nothing was surfaced - the edited lines (or, on a full scan, the whole scanned scope) came back clean.
     * @param int                $suppressedCount - How many findings were hidden for sitting outside the change or already existing, surfaced as the payload's suppressed count so the agent knows some were held back.
     * @param array<int, string> $identities      - Disambiguated stable identity for every input finding, kept and suppressed alike, keyed by `spl_object_id($finding)`; the command reads it only to stamp each kept finding. Empty (the default) means no map was carried, so the report computes each identity on the fly.
     */
    public function __construct(
        public array $findings,
        public int $suppressedCount,
        public array $identities = [],
    ) {
    }
}
