<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use RuntimeException;

/**
 * Thrown when an Infection mutation report is missing or cannot be parsed.
 *
 * Raised when `--infection-report` points at a file that isn't there or doesn't hold the JSON gruff
 * expects. The CLI turns it into a readable note so the user knows to re-run Infection or fix the path,
 * rather than getting a stack trace where mutation feedback should be.
 */
final class MutationReportException extends RuntimeException
{
}
