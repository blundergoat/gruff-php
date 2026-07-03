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
     * Record that a single rule invocation against one unit completed.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $ruleId - Rule identifier as declared in the rule's RuleDefinition.
     * @param int    $durationNs - Wall-clock nanoseconds the rule spent in analyse().
     *
     * @return void
     */
    public function onRuleExecuted(string $ruleId, int $durationNs): void;
}
