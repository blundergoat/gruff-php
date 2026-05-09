<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

/**
 * Marker for rules that can safely scan non-PHP text/config files.
 */
interface SourceTextRuleInterface extends RuleInterface
{
}
