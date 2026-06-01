<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

/**
 * Reports project-owned standalone functions with no supported calls.
 */
final class UnusedInternalFunctionRule extends AbstractUnusedInternalSymbolRule
{
    /**
     * Stable rule identifier for unused internal function findings.
     */
    public const ID = 'dead-code.unused-internal-function';

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
        return 'Unused internal function';
    }

    /**
     * Return the rule-list description.
     *
     * @return string - description of the supported project-wide function-call boundary
     */
    protected function description(): string
    {
        return 'Project-owned standalone function with no supported direct function-call references.';
    }

    /**
     * Return the symbol family this rule reports.
     *
     * @return string - function declaration family
     */
    protected function symbolFamily(): string
    {
        return 'function';
    }
}
