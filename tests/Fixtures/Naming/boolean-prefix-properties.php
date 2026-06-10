<?php

declare(strict_types=1);

namespace Fixtures\Naming;

final class BooleanPrefixPropertiesFixture
{
    public bool $isPest       = false;
    public bool $active       = true;
    public bool $emitted      = false;
    public bool $changedOnly  = false;
    public bool $infectionRun = false;
    public ?bool $valid       = null;
    public bool|null $silent  = null;
    public bool|string $flag  = false;
    public bool $is_valid     = false;
    public bool $force        = false;
    public bool $forceShould  = false;

    public function __construct(private bool $interactive, private bool $infectionRunCtor)
    {
    }

    public function configure(bool $active, bool $isPest, bool $changedOnly, ?bool $strict, bool|null $infectionRun, bool|string $flag): void
    {
        echo $active ? 'active' : 'inactive';
        echo $isPest ? 'pest' : 'phpunit';
        echo $changedOnly ? 'changed' : 'all';
        echo $strict === true ? 'strict' : 'loose';
        echo $infectionRun === true ? 'infection' : 'plain';
        echo is_bool($flag) && $flag ? 'flagged' : 'clear';
    }
}
