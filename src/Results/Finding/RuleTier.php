<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * Identifies the release tier that introduced or owns a rule.
 */
enum RuleTier: string
{
    /**
     * Initial v0.1 rule catalog tier.
     */
    case V01 = 'v0.1';
}
