<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

/**
 * Reports project-owned standalone constants with no supported fetches.
 */
final class UnusedInternalConstantRule extends AbstractUnusedInternalSymbolRule
{
    /**
     * Stable rule identifier for unused internal constant findings.
     */
    public const ID = 'dead-code.unused-internal-constant';

    /**
     * Return the rule id.
     *
     * @return string - stable rule id
     */
    protected function id(): string
    {
        return self::ID;
    }

    /**
     * Return the rule-list name.
     *
     * @return string - display name for list-rules output
     */
    protected function name(): string
    {
        return 'Unused internal constant';
    }

    /**
     * Return the rule-list description.
     *
     * @return string - description of the supported project-wide constant-fetch boundary
     */
    protected function description(): string
    {
        return 'Project-owned standalone constant with no supported direct constant-fetch references.';
    }

    /**
     * Return the symbol family this rule reports.
     *
     * @return string - constant declaration family
     */
    protected function symbolFamily(): string
    {
        return 'constant';
    }
}
