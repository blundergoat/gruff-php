<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Flags a process command built from request-controlled data - a tainted backtick string, `new Process(...)`,
 * or `Process::fromShellCommandline(...)` - the shape that lets an attacker inject shell commands (RCE).
 *
 * Runs per file over shell-exec expressions and Symfony Process constructors/factories whose command reaches
 * user input. Warning, medium confidence - a request-tainted command is a likely RCE sink, not a certain one.
 */
final class ProcessCommandConstructionRule implements RuleInterface
{
    /**
     * Stable rule identifier for request-controlled process commands.
     */
    public const ID = 'security.process-command-construction';

    /**
     * Describes the process-command-construction rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning severity at medium confidence: request-tainted process commands are a likely RCE sink, not certain.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Process command construction',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Reports each process command built from request-controlled data.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for request-controlled process commands.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every backtick shell-exec in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\ShellExec::class) as $shellExec) {
            // Request-controlled data inside a shell string is the injection risk.
            if (SecurityNodeHelper::containsUserInput($shellExec)) {
                $findings[] = $this->finding($analysisUnit, $shellExec, 'shell-exec');
            }
        }

        // Check every object construction for a Symfony Process.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\New_::class) as $new) {
            // Only a Symfony Process constructor builds a command line.
            if (!SecurityNodeHelper::hasMatchingClassName($new->class, ['Symfony\Component\Process\Process', 'Process'])) {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($new->args, 0);
            // A request-controlled command argument is the risk.
            if ($firstArg !== null && SecurityNodeHelper::containsUserInput($firstArg)) {
                $findings[] = $this->finding($analysisUnit, $new, 'symfony-process');
            }
        }

        // Check every static call for Process::fromShellCommandline.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $staticCall) {
            // Only Process::fromShellCommandline parses a raw shell string.
            if (
                !SecurityNodeHelper::hasMatchingClassName($staticCall->class, ['Symfony\Component\Process\Process', 'Process'])
                || SecurityNodeHelper::methodName($staticCall) !== 'fromshellcommandline'
            ) {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($staticCall->args, 0);
            // A request-controlled command line is the risk.
            if ($firstArg !== null && SecurityNodeHelper::containsUserInput($firstArg)) {
                $findings[] = $this->finding($analysisUnit, $staticCall, 'process-shell-commandline');
            }
        }

        return $findings;
    }

    /**
     * Builds the process-command finding.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path reported to the reviewer.
     * @param Node         $node - Tainted sink node whose start line anchors the finding for the reviewer.
     * @param string       $sink - Sink discriminator (shell-exec, symfony-process, process-shell-commandline)
     *                                   echoed into the message and metadata so a reviewer sees which construct fired.
     *
     * @return Finding - Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $sink): Finding
    {
        // Emit a fixed warning: every caller already confirmed the sink carries request-controlled data.
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('Process command construction with request-controlled data detected: %s.', $sink),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Build process arguments from allow-listed values and avoid shell parsing for request-controlled input.',
            metadata:    [
                'sink' => $sink,
            ],
        );
    }
}
