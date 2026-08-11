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
 * Flags process command strings built from dynamic data, including request-tainted backticks, Symfony Process
 * shell entry points, and procedural command functions - shapes that can let an attacker inject commands (RCE).
 *
 * Runs per file over shell-exec expressions, procedural command functions, and Symfony Process entry points.
 * Warning, medium confidence - a request-tainted command is a likely RCE sink, not a certain one.
 */
final class ProcessCommandConstructionRule implements RuleInterface
{
    /**
     * Stable rule identifier for request-controlled process commands.
     */
    public const ID = 'security.process-command-construction';

    /**
     * Procedural functions whose first argument is interpreted as a command string.
     *
     * @var list<string>
     */
    private const PROCEDURAL_COMMAND_SINKS = ['exec', 'passthru', 'popen', 'proc_open', 'shell_exec', 'system'];

    /** Shell executables whose argv command flag restores shell-string parsing inside proc_open(). */
    private const SHELL_INTERPRETER_NAMES = ['bash', 'cmd', 'cmd.exe', 'dash', 'ksh', 'powershell', 'pwsh', 'sh', 'zsh'];

    /** POSIX-style shells that accept combined short options such as `-lc`. */
    private const POSIX_SHELL_INTERPRETER_NAMES = ['bash', 'dash', 'ksh', 'sh', 'zsh'];

    /** cmd.exe switches whose next argv item is interpreted as command text. */
    private const CMD_COMMAND_FLAGS = ['/c', '/k'];

    /** PowerShell switches whose next argv item is interpreted as command text. */
    private const POWERSHELL_COMMAND_FLAGS = ['-c', '-command', '-ec', '-encodedcommand'];

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
     * Reports each process command built from request-controlled or directly concatenated data.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unsafe process command construction.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every backtick shell-exec in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\ShellExec::class) as $shellExec) {
            // Request-controlled data inside a shell string is the injection risk.
            if (SecurityNodeHelper::containsUserInput($shellExec)) {
                $findings[] = $this->requestControlledFinding($analysisUnit, $shellExec, 'shell-exec');
            }
        }

        $findings = array_merge($findings, $this->proceduralCommandFindings($analysisUnit));

        // Check every object construction for a Symfony Process.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\New_::class) as $new) {
            // Only a Symfony Process constructor builds a command line.
            if (!SecurityNodeHelper::hasMatchingClassName($new->class, ['Symfony\Component\Process\Process', 'Process'])) {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($new->args, 0);
            // A request-controlled command argument is the risk.
            if ($firstArg !== null && SecurityNodeHelper::containsUserInput($firstArg)) {
                $findings[] = $this->requestControlledFinding($analysisUnit, $new, 'symfony-process');
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
                $findings[] = $this->requestControlledFinding($analysisUnit, $staticCall, 'process-shell-commandline');
            }
        }

        return $findings;
    }

    /**
     * Reports procedural command APIs whose command argument is assembled dynamically.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit containing candidate function calls.
     *
     * @return list<Finding> - Findings for known procedural command boundaries.
     */
    private function proceduralCommandFindings(AnalysisUnit $analysisUnit): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $functionCall) {
            $functionName = SecurityNodeHelper::globalFunctionName($functionCall);
            // Only these APIs give the first argument command-string semantics.
            if ($functionName === null || !in_array($functionName, self::PROCEDURAL_COMMAND_SINKS, true)) {
                continue;
            }

            $commandArgument = SecurityNodeHelper::argumentValue($functionCall->args, 0, ['command']);
            // proc_open() argv arrays bypass shell parsing unless they explicitly invoke a shell command mode.
            if ($functionName === 'proc_open' && $commandArgument instanceof Expr\Array_) {
                $commandArgument = $this->shellCommandFromArgumentVector($commandArgument);
            }

            // Literal commands are static; concatenation or interpolation creates the injection boundary.
            if ($commandArgument !== null && SecurityNodeHelper::containsConcatOrInterpolation($commandArgument)) {
                $findings[] = $this->dynamicCommandFinding($analysisUnit, $functionCall, $functionName);
            }
        }

        return $findings;
    }

    /**
     * Selects command text from an argv array that deliberately launches a shell interpreter.
     *
     * @param Expr\Array_ $argumentVector - proc_open() command array; an empty array has no executable or shell text.
     *
     * @return Expr|null - Shell command expression, or null when PHP executes the argv array directly without a shell.
     */
    private function shellCommandFromArgumentVector(Expr\Array_ $argumentVector): ?Expr
    {
        $firstItem = $argumentVector->items[0] ?? null;
        // Only a literal shell executable proves that this otherwise-safe argv form restores shell parsing.
        if ($firstItem === null || !$firstItem->value instanceof Node\Scalar\String_) {
            return null;
        }

        $interpreterPath = str_replace('\\', '/', strtolower($firstItem->value->value));
        $interpreterName = basename($interpreterPath);
        // Direct executables receive each dynamic array item as one argument, so they are not command-injection sinks.
        if (!in_array($interpreterName, self::SHELL_INTERPRETER_NAMES, true)) {
            return null;
        }

        // A recognised command flag makes its following item shell source rather than an ordinary argv value.
        foreach (array_slice($argumentVector->items, 1, null, true) as $index => $item) {
            if (!$item->value instanceof Node\Scalar\String_) {
                continue;
            }

            if (!$this->isShellCommandFlag($item->value->value, $interpreterName)) {
                continue;
            }

            return $argumentVector->items[$index + 1]->value ?? null;
        }

        return null;
    }

    /**
     * Reports whether one interpreter argument makes the following argv item executable shell text.
     *
     * @param string $argument        - Literal interpreter option from the proc_open() argv array.
     * @param string $interpreterName - Lowercase executable basename selected from the first argv item.
     *
     * @return bool - True for explicit command switches, including combined POSIX options such as `-lc`.
     */
    private function isShellCommandFlag(string $argument, string $interpreterName): bool
    {
        $normalisedArgument = strtolower($argument);
        if (in_array($interpreterName, ['cmd', 'cmd.exe'], true)) {
            return in_array($normalisedArgument, self::CMD_COMMAND_FLAGS, true);
        }

        if (in_array($interpreterName, ['powershell', 'pwsh'], true)) {
            return in_array($normalisedArgument, self::POWERSHELL_COMMAND_FLAGS, true);
        }

        // POSIX shells permit `-c` to share one short-option cluster with login or execution flags.
        return in_array($interpreterName, self::POSIX_SHELL_INTERPRETER_NAMES, true)
            && preg_match('/^-[a-z]*c[a-z]*$/', $normalisedArgument) === 1;
    }

    /**
     * Builds a finding backed by request-taint evidence.
     *
     * @param AnalysisUnit $analysisUnit - Unit supplying the reported display path.
     * @param Node         $sinkNode     - Sink node supplying the reported line.
     * @param string       $sinkName     - Sink discriminator included in the message and metadata.
     *
     * @return Finding - Security finding.
     */
    private function requestControlledFinding(AnalysisUnit $analysisUnit, Node $sinkNode, string $sinkName): Finding
    {
        return $this->findingForSink(
            $analysisUnit,
            $sinkNode,
            $sinkName,
            'Process command construction with request-controlled data detected',
        );
    }

    /**
     * Builds a finding backed by direct dynamic construction evidence.
     *
     * @param AnalysisUnit $analysisUnit - Unit supplying the reported display path.
     * @param Node         $sinkNode     - Sink node supplying the reported line.
     * @param string       $sinkName     - Sink discriminator included in the message and metadata.
     *
     * @return Finding - Security finding.
     */
    private function dynamicCommandFinding(AnalysisUnit $analysisUnit, Node $sinkNode, string $sinkName): Finding
    {
        return $this->findingForSink(
            $analysisUnit,
            $sinkNode,
            $sinkName,
            'Dynamic process command construction detected',
        );
    }

    /**
     * Builds the shared process-command finding payload.
     *
     * @param AnalysisUnit $analysisUnit   - Unit supplying the reported display path.
     * @param Node         $sinkNode       - Sink node supplying the reported line.
     * @param string       $sinkName       - Sink discriminator included in the message and metadata.
     * @param string       $findingSummary - Evidence-specific message prefix.
     *
     * @return Finding - Security finding.
     */
    private function findingForSink(
        AnalysisUnit $analysisUnit,
        Node $sinkNode,
        string $sinkName,
        string $findingSummary,
    ): Finding {
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('%s: %s.', $findingSummary, $sinkName),
            filePath:    $analysisUnit->file->displayPath,
            line:        $sinkNode->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Build process arguments from allow-listed values and avoid shell parsing for dynamic input.',
            metadata:    [
                'sink' => $sinkName,
            ],
        );
    }
}
