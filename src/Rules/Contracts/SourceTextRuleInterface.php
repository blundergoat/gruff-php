<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Contracts;

/**
 * Marks a rule as safe to run over non-PHP text and config files, not just parsed PHP source.
 *
 * Most rules only make sense on PHP the engine has parsed, but a few (secret scanning, config checks)
 * need to read plain files too. Implementing this interface tells the engine a rule opts in to those
 * extra units, so a user's YAML, env, or lock files get scanned by exactly the rules meant for them
 * and skipped by the rest.
 */
interface SourceTextRuleInterface extends RuleInterface
{
}
