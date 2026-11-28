<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

/**
 * Collects the changed-region and comparison choices for one `analyse` run.
 * `--diff`, `--since`, and `--changed-ranges` supply alternative sources of changed ranges.
 * `--changed-scope` controls range matching; `--diff-vs` and `--changed-only` configure comparison output.
 */
final readonly class ChangeScopeOptions
{
    /**
     * Captures the changed-region options for this run so callers can pass them through as one value.
     *
     * @param string|null $diffMode - Requested `--diff` mode; null leaves the other scope options to determine the scan.
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
