<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

/**
 * Optional hook invoked by RuleRegistry::analyse() for each rule execution.
 *
 * Default analyse runs do not attach an observer; the hook exists for performance
 * instrumentation in CLI runtime mode.
 */
interface RuleRunnerObserver
{
    /**
     * Record the elapsed time of one completed rule operation.
     *
     * @param string $ruleId - Rule identifier as declared in the rule's RuleDefinition.
     * @param int $durationNs - Elapsed nanoseconds for the completed rule operation.
     *
     * @return void - Records timing information for one completed rule operation.
     */
    public function onRuleExecuted(string $ruleId, int $durationNs): void;
}
