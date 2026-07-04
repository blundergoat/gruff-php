<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

/**
 * Marks a project-level rule accumulator that also needs the run's non-PHP text and config files.
 *
 * Project rules see the whole codebase at once rather than one file at a time. Implementing this
 * interface tells the engine to feed such an accumulator the text/config units too (not only PHP), so
 * a cross-file check like secret scanning or config consistency can weigh every relevant file the
 * user's project contains.
 */
interface ProjectSourceTextRuleAccumulator extends ProjectRuleAccumulator
{
}
