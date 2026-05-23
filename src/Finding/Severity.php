<?php

declare(strict_types=1);

namespace GruffPhp\Finding;

/**
 * Represents the level at which a finding should affect feedback and exits.
 */
enum Severity: string
{
    /**
     * Informational issue that should guide cleanup without failing strict gates.
     */
    case Advisory = 'advisory';

    /**
     * Issue serious enough to fail warning-level quality gates.
     */
    case Warning = 'warning';

    /**
     * Issue severe enough to fail error-level quality gates.
     */
    case Error = 'error';
}
