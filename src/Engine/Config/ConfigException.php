<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use RuntimeException;

/**
 * Thrown when the analyzer cannot make sense of its configuration.
 *
 * Raised while loading `.gruff-php.yaml` (or an equivalent) when a value is missing, malformed, or
 * contradictory - a bad severity name, an unknown rule id, an unreadable file. The CLI catches it and
 * turns it into a clear "your config is wrong, here's why" message rather than a stack trace, so the
 * user can fix the file and re-run.
 */
final class ConfigException extends RuntimeException
{
}
