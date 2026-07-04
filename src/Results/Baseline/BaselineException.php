<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use RuntimeException;

/**
 * Thrown when a baseline file cannot be read or trusted.
 *
 * Raised while loading the baseline of already-accepted findings when the file is missing where one
 * was expected, malformed, or otherwise unusable. The CLI surfaces it as a plain message so the user
 * knows to regenerate or fix their baseline rather than seeing a raw crash where suppression should
 * have happened.
 */
final class BaselineException extends RuntimeException
{
}
