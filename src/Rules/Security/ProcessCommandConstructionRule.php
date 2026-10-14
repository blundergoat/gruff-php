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
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar;

/**
 * Reports a shell command built from data the code does not control, where an attacker could run their own commands.
 *
 * A user who wrote `exec('ping -c 4 ' . $target)` gets one warning on that call, to escape the value or pass an argv array.
 * It covers backtick strings, exec()-style functions, proc_open() argv arrays that launch a shell, and Symfony Process.
 *
 * Spliced values that cannot break out of their argument stay quiet:
 * - an escapeshellarg() result outside an open quote, or an `(int)` cast;
 * - a local the code only ever sets to its own plain words, such as `$mode = $isDir ? 'J' : 'H'`.
 */
final class ProcessCommandConstructionRule implements RuleInterface
{
    /**
     * Stable rule identifier for request-controlled process commands.
     */
    public const ID = 'security.process-command-construction';

    /**
     * Procedural functions whose first argument the shell parses as a command line.
     *
     * @var list<string>
     */
    private const PROCEDURAL_COMMAND_SINKS = ['exec', 'passthru', 'popen', 'proc_open', 'shell_exec', 'system'];

    /**
     * Functions whose result is one shell-escaped argument, so splicing it into a command opens no injection point.
     *
     * escapeshellcmd() is deliberately absent: it leaves argument separators in place.
     *
     * @var list<string>
     */
    private const SHELL_ARGUMENT_ESCAPERS = ['escapeshellarg'];

    /** Shell executables whose command flag turns a proc_open() argv item back into shell text. */
    private const SHELL_INTERPRETER_NAMES = ['bash', 'cmd', 'cmd.exe', 'dash', 'ksh', 'powershell', 'pwsh', 'sh', 'zsh'];

    /** POSIX-style shells that accept combined short options such as `-lc`. */
    private const POSIX_SHELL_INTERPRETER_NAMES = ['bash', 'dash', 'ksh', 'sh', 'zsh'];

    /** cmd.exe switches whose next argv item runs as command text. */
    private const CMD_COMMAND_FLAGS = ['/c', '/k'];

    /** PowerShell switches whose next argv item runs as command text. */
    private const POWERSHELL_COMMAND_FLAGS = ['-c', '-command', '-ec', '-encodedcommand'];

    /**
     * Describes the rule for `list-rules` and for each finding it reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: a command built from outside data is a likely remote-code-execution point, not a proven one.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Process command construction',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape'      => 'A procedural command whose spliced value is constrained by an earlier allow-list or validator rather than escaped where it is spliced.',
                    'mitigation' => 'Only escapeshellarg() and (int) operands count as escaped at the concatenation itself, so escape the value there or pass a proc_open() argv array.',
                ],
            ],
        );
    }

    /**
     * Reports each shell command built from request data or from an unescaped dynamic value.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per unsafe command; empty when every command is literal, escaped or an argv array.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Backtick strings run through the shell, such as `` `ls {$_GET['dir']}` ``.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\ShellExec::class) as $shellExec) {
            // Request data inside the backticks is the injection point.
            if (SecurityNodeHelper::containsUserInput($shellExec)) {
                $findings[] = $this->requestControlledFinding($analysisUnit, $shellExec, 'shell-exec');
            }
        }

        $findings = array_merge($findings, $this->proceduralCommandFindings($analysisUnit));

        // `new Process(...)` from Symfony runs its first argument as the command.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\New_::class) as $construction) {
            // Other classes, even ones taking a string, do not start a process.
            if (!SecurityNodeHelper::hasMatchingClassName($construction->class, ['Symfony\Component\Process\Process', 'Process'])) {
                continue;
            }

            $commandLine = SecurityNodeHelper::argumentValue($construction->args, 0);
            // A command that request data reaches is the injection point.
            if ($commandLine !== null && SecurityNodeHelper::containsUserInput($commandLine)) {
                $findings[] = $this->requestControlledFinding($analysisUnit, $construction, 'symfony-process');
            }
        }

        // `Process::fromShellCommandline(...)` hands a raw string to the shell.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $staticCall) {
            // Other static calls, including other Process factories, do not parse a shell string.
            if (
                !SecurityNodeHelper::hasMatchingClassName($staticCall->class, ['Symfony\Component\Process\Process', 'Process'])
                || SecurityNodeHelper::methodName($staticCall) !== 'fromshellcommandline'
            ) {
                continue;
            }

            $commandLine = SecurityNodeHelper::argumentValue($staticCall->args, 0);
            // A command line that request data reaches is the injection point.
            if ($commandLine !== null && SecurityNodeHelper::containsUserInput($commandLine)) {
                $findings[] = $this->requestControlledFinding($analysisUnit, $staticCall, 'process-shell-commandline');
            }
        }

        return $findings;
    }

    /**
     * Reports exec()-style calls whose command splices in a value that could break out of its argument.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file holding the candidate calls.
     *
     * @return list<Finding> - One finding per unsafe call; empty when every command is literal or safely escaped.
     */
    private function proceduralCommandFindings(AnalysisUnit $analysisUnit): array
    {
        $findings = [];

        // Every function call is a candidate, because exec() and friends can be called anywhere in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $functionCall) {
            $functionName = SecurityNodeHelper::globalFunctionName($functionCall);
            // Only these functions hand their first argument to the shell.
            if ($functionName === null || !in_array($functionName, self::PROCEDURAL_COMMAND_SINKS, true)) {
                continue;
            }

            $commandArgument = SecurityNodeHelper::sinkArgumentValue($functionCall, 0);
            // A proc_open() argv array skips the shell, unless it launches one such as `['sh', '-c', $script]`.
            if ($functionName === 'proc_open' && $commandArgument instanceof Expr\Array_) {
                $commandArgument = $this->shellCommandFromArgumentVector($commandArgument);
            }

            // A literal command is fixed; splicing in an unescaped value is what opens the injection point.
            if ($commandArgument !== null && $this->splicesUnescapedValue($analysisUnit, $commandArgument)) {
                $findings[] = $this->dynamicCommandFinding($analysisUnit, $functionCall, $functionName);
            }
        }

        return $findings;
    }

    /**
     * Reports whether a command splices in a value that could break out of its argument.
     *
     * A string built inside another call, such as sprintf(), always counts, because that call can splice other values around it.
     * escapeshellarg() counts as safe only outside an open quote, where its own quotes still delimit the value.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file searched for the assignments to a spliced local.
     * @param Expr         $command - Command argument passed to an exec()-style function.
     *
     * @return bool - True when any spliced part is not literal text, an `(int)` cast, an escaped argument or a plain-word local;
     *                false for a fully literal or fully escaped command.
     */
    private function splicesUnescapedValue(AnalysisUnit $analysisUnit, Expr $command): bool
    {
        $isShellSafe = fn (Node $node): bool => $this->isShellSafeOperand($node);
        // Each concatenation or interpolated string in the command is judged separately.
        foreach (SecurityNodeHelper::outermostStringConstructions($command, $isShellSafe) as $construction) {
            // A string built inside sprintf() or str_replace() can have other values spliced around it.
            if (SecurityNodeHelper::hasCallBetween($construction, $command)) {
                return true;
            }

            $literalText = '';
            // Walk the parts left to right, tracking the literal text so far to know whether a quote is open.
            foreach (SecurityNodeHelper::flattenedOperands($construction) as $operand) {
                // The code's own text is fixed; it only matters for the quote state of later parts.
                if ($operand instanceof Scalar\String_ || $operand instanceof InterpolatedStringPart) {
                    $literalText .= $operand->value;
                    continue;
                }

                // An `(int)` cast, or escapeshellarg() outside an open quote, cannot break out of its argument.
                if ($operand instanceof Expr\Cast\Int_
                    || ($this->isShellSafeOperand($operand) && !SecurityNodeHelper::hasOpenQuote($literalText, '\'"', false))) {
                    continue;
                }

                // A local the code only ever sets to its own plain words, such as `$mode = $isDir ? 'J' : 'H'`, carries no outside data.
                if (LocalAssignmentEvidence::onlyEverHolds($analysisUnit, $operand, fn(Expr $assigned): bool => $this->isPlainWordOrChoice($assigned), false)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether an assigned value is one plain word, or a ternary choosing between plain words.
     *
     * @param Expr $assigned - Right-hand side of an assignment to a spliced local.
     *
     * @return bool - True for letters, digits and `_ . / : = + -` only; a quote, backslash, space or shell character never qualifies.
     */
    private function isPlainWordOrChoice(Expr $assigned): bool
    {
        // A literal word, such as `'J'` or `'-la'`, is judged by its characters.
        if ($assigned instanceof Scalar\String_) {
            // Only characters the shell passes through unchanged, so the word cannot open a quote or start a second command.
            return preg_match('~^[\w./:=+-]+$~', $assigned->value) === 1;
        }

        // The ternary's condition picks a word but never reaches the command, so only its two arms decide.
        return $assigned instanceof Expr\Ternary && $assigned->if !== null
            && $this->isPlainWordOrChoice($assigned->if) && $this->isPlainWordOrChoice($assigned->else);
    }

    /**
     * Reports whether a spliced part is an escapeshellarg() result or an `(int)` cast.
     *
     * @param Node $operand - Part of a command concatenation or interpolated string.
     *
     * @return bool - True for an escapeshellarg() call or an `(int)` cast; false for any other value.
     */
    private function isShellSafeOperand(Node $operand): bool
    {
        // An integer prints only digits and a sign.
        if ($operand instanceof Expr\Cast\Int_) {
            return true;
        }

        return $operand instanceof Expr\FuncCall && !$operand->isFirstClassCallable()
            && in_array(SecurityNodeHelper::globalFunctionName($operand), self::SHELL_ARGUMENT_ESCAPERS, true);
    }

    /**
     * Picks the shell-text item out of a proc_open() argv array that deliberately launches a shell, such as `['sh', '-c', $script]`.
     *
     * @param Expr\Array_ $argumentVector - proc_open() command array; an empty array has no executable or shell text.
     *
     * @return Expr|null - The item the shell runs as a command; null when PHP runs the argv array directly with no shell.
     */
    private function shellCommandFromArgumentVector(Expr\Array_ $argumentVector): ?Expr
    {
        $firstItem = $argumentVector->items[0] ?? null;
        // Only a literal shell name proves that this otherwise-safe argv form brings shell parsing back.
        if ($firstItem === null || !$firstItem->value instanceof Node\Scalar\String_) {
            return null;
        }

        $interpreterPath = str_replace('\\', '/', strtolower($firstItem->value->value));
        $interpreterName = basename($interpreterPath);
        // A direct executable such as `git` receives each array item as one argument, so nothing is injected.
        if (!in_array($interpreterName, self::SHELL_INTERPRETER_NAMES, true)) {
            return null;
        }

        // A command flag such as `-c` makes the next item shell source rather than an ordinary argument.
        foreach (array_slice($argumentVector->items, 1, null, true) as $index => $argvItem) {
            // A computed option cannot be read, so it is not treated as a command flag.
            if (!$argvItem->value instanceof Node\Scalar\String_) {
                continue;
            }

            // Ordinary options, such as `-l` on its own, leave the next item as plain data.
            if (!$this->isShellCommandFlag($argvItem->value->value, $interpreterName)) {
                continue;
            }

            return $argumentVector->items[$index + 1]->value ?? null;
        }

        return null;
    }

    /**
     * Reports whether one interpreter option makes the following argv item run as shell text.
     *
     * @param string $argument        - Literal interpreter option from the proc_open() argv array.
     * @param string $interpreterName - Lowercase executable name taken from the first argv item.
     *
     * @return bool - True for a command switch, including combined POSIX options such as `-lc`; false for any other option.
     */
    private function isShellCommandFlag(string $argument, string $interpreterName): bool
    {
        $normalisedArgument = strtolower($argument);
        // Windows cmd.exe runs the text after `/c` or `/k`.
        if (in_array($interpreterName, ['cmd', 'cmd.exe'], true)) {
            return in_array($normalisedArgument, self::CMD_COMMAND_FLAGS, true);
        }

        // PowerShell runs the text after `-command` or an encoded command.
        if (in_array($interpreterName, ['powershell', 'pwsh'], true)) {
            return in_array($normalisedArgument, self::POWERSHELL_COMMAND_FLAGS, true);
        }

        // POSIX shells let `-c` share one short-option cluster with other flags, as in `bash -lc`.
        return in_array($interpreterName, self::POSIX_SHELL_INTERPRETER_NAMES, true)
            && preg_match('/^-[a-z]*c[a-z]*$/', $normalisedArgument) === 1;
    }

    /**
     * Builds the finding for a command that request data reaches.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file supplying the reported path.
     * @param Node         $sinkNode     - Command call or backtick string supplying the reported line.
     * @param string       $sinkName     - Kind of command, shown in the message and metadata.
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
     * Builds the finding for a command that splices in an unescaped dynamic value.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file supplying the reported path.
     * @param Node         $sinkNode     - Command call supplying the reported line.
     * @param string       $sinkName     - Function name, shown in the message and metadata.
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
     * Builds the process-command finding the user sees, with the shared severity and advice.
     *
     * @param AnalysisUnit $analysisUnit   - Parsed file supplying the reported path.
     * @param Node         $sinkNode       - Command call or backtick string supplying the reported line.
     * @param string       $sinkName       - Kind of command, shown in the message and metadata.
     * @param string       $findingSummary - Evidence-specific start of the message.
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
