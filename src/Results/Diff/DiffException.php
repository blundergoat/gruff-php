<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

use RuntimeException;

/**
 * Thrown when gruff cannot get the Git diff a changed-only or branch review depends on.
 *
 * Raised when a `--diff`, `--diff-vs`, or hook run asks Git for the changed lines and the lookup
 * fails - not a repository, an unknown ref, or Git itself erroring. The CLI reports it clearly so the
 * user can correct the ref or fall back to a full scan instead of losing the run to a stack trace.
 */
final class DiffException extends RuntimeException
{
}
