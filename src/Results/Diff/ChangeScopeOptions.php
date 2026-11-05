<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

/**
 * The "scan only part of the tree" choices for one `analyse` run, gathered into a single readonly value.
 *
 * A user narrows a run in one of two ways. `--diff`, `--since`, and `--changed-ranges` each pick a
 * changed region a different way and are mutually exclusive; `--diff-vs` instead names a ref to review
 * against, with `--changed-only` reporting just the files that moved. `--changed-scope` decides how a
 * line range expands to the findings it covers. They travel together because they are validated
 * against each other rather than independently, and because discovery, the review builder, and the
 * pipeline each need several of them at once.
 */
final readonly class ChangeScopeOptions
{
    /**
     * Captures every changed-region choice for this run, so callers read one value instead of six flags.
     *
     * @param string|null $diffMode - Requested `--diff` mode, or null when `--diff` was not supplied so the full tree is analysed.
     * @param string|null $since - Git base ref from `--since`, or null when the user did not scope the run to changes since a ref.
     * @param string|null $changedRanges - Explicit line ranges from `--changed-ranges`, or null when the user named none.
     * @param string $changedScope - How ranges expand to findings via `--changed-scope`: symbol, hunk, or file.
     * @param string|null $diffVs - Comparison ref from `--diff-vs`, or null when the user is not comparing against another ref.
     * @param bool $isChangedOnly - Set by `--changed-only`; when true, only files that changed versus `--diff-vs` are reported.
     */
    public function __construct(
        public ?string $diffMode,
        public ?string $since,
        public ?string $changedRanges,
        public string $changedScope,
        public ?string $diffVs,
        public bool $isChangedOnly,
    ) {
    }
}
