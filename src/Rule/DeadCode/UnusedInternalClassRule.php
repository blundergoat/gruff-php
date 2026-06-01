<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

/**
 * Reports project-owned class-like declarations with no supported references.
 */
final class UnusedInternalClassRule extends AbstractUnusedInternalSymbolRule
{
    /**
     * Stable rule identifier for unused internal class-like findings.
     */
    public const ID = 'dead-code.unused-internal-class';

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
        return 'Unused internal class-like';
    }

    /**
     * Return the rule-list description.
     *
     * @return string - description of the supported project-wide reference boundary
     */
    protected function description(): string
    {
        return 'Project-owned class, interface, trait, or enum with no supported static references.';
    }

    /**
     * Return the symbol family this rule reports.
     *
     * @return string - class-like declaration family
     */
    protected function symbolFamily(): string
    {
        return 'class-like';
    }
}
