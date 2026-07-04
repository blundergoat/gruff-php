<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * Which release line a rule belongs to - the catalog tier that introduced and owns it.
 *
 * Rules are versioned in tiers so the catalog can grow without silently changing what an existing
 * project is graded against; a rule's tier tells the user which generation of the rule set it came
 * from, which matters when they compare results across gruff versions. Only the initial tier exists
 * today, but the enum leaves room for later tiers to be added without reshuffling the old ones.
 */
enum RuleTier: string
{
    /**
     * The original v0.1 rule catalog - every rule shipped so far belongs to this first tier.
     */
    case V01 = 'v0.1';
}
