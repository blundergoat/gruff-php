<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

use RuntimeException;

/**
 * Signals git diff lookup failures.
 */
final class DiffException extends RuntimeException
{
}
