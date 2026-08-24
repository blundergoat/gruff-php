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
use PhpParser\Node\Scalar;

/**
 * Flags a log or dump call whose argument carries request-controlled or secret-looking data - `error_log`,
 * `var_dump`, a PSR-3 `$logger->error(...)` - so a token, password, or payload does not end up in the logs.
 *
 * Runs per file over the modelled log functions and logger methods, skipping purely static messages.
 * Warning, medium confidence - taint and secret detection are heuristic.
 */
final class SensitiveDataLoggingRule implements RuleInterface
{
    /**
     * Stable rule identifier for sensitive data logging.
     */
    public const ID = 'security.sensitive-data-logging';

    /**
     * Global functions that write or dump a value to a log or the output.
     *
     * @var list<string>
     */
    private const LOG_FUNCTIONS = ['error_log', 'print_r', 'var_dump'];

    /**
     * PSR-3 logger method names whose first argument is the logged message.
     *
     * @var list<string>
     */
    private const LOG_METHODS = ['alert', 'critical', 'debug', 'emergency', 'error', 'info', 'log', 'notice', 'warning'];

    /**
     * Describes the sensitive-data-logging rule for the registry and reports.
     *
     * @return RuleDefinition - identity, pillar, tier, and default severity/confidence the registry
     *   uses to list and configure this rule
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Sensitive data logging',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape'      => 'A log message whose static text merely names a credential, such as \'Authorization failed for user \' concatenated with an identifier.',
                    'mitigation' => 'A string literal containing a credential word is itself read as sensitive context, so reword the message or pass the identifier through the context array.',
                ],
                [
                    'shape'      => 'A non-logger method sharing a PSR-3 name, such as a response builder\'s error() or a metrics client\'s info(), called with a dynamic argument.',
                    'mitigation' => 'Logger calls are matched by method name with no receiver-type check, so accept the finding or rename the local method.',
                ],
            ],
        );
    }

    /**
     * Reports each log sink that includes request-controlled or secret-looking values.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per flagged log sink across function, method, and static
     *   calls; empty when nothing leaks request or secret data
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check global log and dump function calls.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // Only the modelled log/dump functions are sinks.
            if ($name === null || !in_array($name, self::LOG_FUNCTIONS, true)) {
                continue;
            }

            // Flag the call when an argument carries request or secret data.
            if ($this->hasSensitiveArgument($call->args)) {
                $findings[] = $this->finding($analysisUnit, $call, $name);
            }
        }

        // Check logger method calls such as $logger->error(...).
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\MethodCall::class) as $call) {
            array_push($findings, ...$this->loggerCallFindings($analysisUnit, $call));
        }

        // Check static logger calls such as Log::error(...).
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $call) {
            array_push($findings, ...$this->loggerCallFindings($analysisUnit, $call));
        }

        return $findings;
    }

    /**
     * Builds the logger-call findings for one method or static call.
     *
     * @param AnalysisUnit                    $analysisUnit - Unit being scanned; supplies the display path for findings.
     * @param Expr\MethodCall|Expr\StaticCall $call - Possible logger call whose name and arguments are checked.
     *
     * @return list<Finding> - zero or one finding; a single logger call is one sink, flagged once
     */
    private function loggerCallFindings(AnalysisUnit $analysisUnit, Expr\MethodCall|Expr\StaticCall $call): array
    {
        $method = SecurityNodeHelper::methodName($call);
        if ($method === null || !in_array($method, self::LOG_METHODS, true)) {
            // The callee is not one of the modelled logger methods, so it cannot be a sink we track.
            return [];
        }

        if (!$this->hasSensitiveArgument($call->args)) {
            // Every argument is static or non-sensitive, so nothing request-controlled can leak here.
            return [];
        }

        return [$this->finding($analysisUnit, $call, $method)];
    }

    /**
     * Reports whether any argument carries request-tainted or secret-bearing data.
     *
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args - Logger-call arguments to scan for request-tainted or secret-bearing values.
     *
     * @return bool - true on the first argument carrying request-tainted or secret-bearing data;
     *   false when every argument is static or non-sensitive
     */
    private function hasSensitiveArgument(array $args): bool
    {
        // Weigh each argument to the log call.
        foreach ($args as $arg) {
            // A spread placeholder is not a plain argument.
            if (!$arg instanceof Node\Arg) {
                continue;
            }

            // A purely static message leaks nothing.
            if ($this->isStaticLogArgument($arg->value)) {
                continue;
            }

            if (SecurityNodeHelper::containsUserInput($arg->value) || SecurityNodeHelper::containsSensitiveReference($arg->value)) {
                // One request-tainted or secret-bearing argument is enough to flag the log call.
                return true;
            }
        }

        // No argument carried request or sensitive data, so this log call is safe to ignore.
        return false;
    }

    /**
     * Reports whether a logger argument resolves to only static, compile-time values.
     *
     * @param Expr $expr - Argument expression to classify; recursed into for arrays and concatenations.
     *
     * @return bool - true when the argument resolves to only compile-time constant message/context
     *   values; false when any part can hold runtime data
     */
    private function isStaticLogArgument(Expr $expr): bool
    {
        if ($expr instanceof Scalar) {
            // A literal value carries no runtime data, so it can never leak request input.
            return true;
        }

        if ($expr instanceof Expr\ClassConstFetch) {
            // Class constants are compile-time fixed, so they are safe message tokens.
            return true;
        }

        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());

            // Only the language literals are static; any other constant may resolve to runtime data.
            return in_array($name, ['false', 'null', 'true'], true);
        }

        // An array is static only when every element is static.
        if ($expr instanceof Expr\Array_) {
            // Weigh each element of the array.
            foreach ($expr->items as $item) {
                if ($item->unpack || !$this->isStaticLogArgument($item->value)) {
                    // A spread or any non-static element can carry runtime data, so the whole array is unsafe.
                    return false;
                }
            }

            // Every element is itself static, so the array as a whole leaks nothing.
            return true;
        }

        if ($expr instanceof Expr\BinaryOp\Concat) {
            // A concatenation is static only when both operands are; either dynamic side taints the result.
            return $this->isStaticLogArgument($expr->left) && $this->isStaticLogArgument($expr->right);
        }

        // Anything not matched above (variables, calls, fetches) may hold runtime data, so treat it as dynamic.
        return false;
    }

    /**
     * Builds the sensitive-data-logging finding.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path recorded on the finding.
     * @param Node         $node - Log call flagged as leaking; its start line locates the finding.
     * @param string       $sink - Name of the log function or method, surfaced in the message and metadata.
     *
     * @return Finding - medium-confidence security warning located at the call's start line, naming
     *   the sink in its message and metadata
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $sink): Finding
    {
        // Detection is heuristic, so emit a medium-confidence warning naming the sink for the reviewer.
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('Logging or dumping of request-controlled or sensitive-looking data detected: %s.', $sink),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Log stable identifiers or redacted summaries instead of request payloads, tokens, passwords, or secret-bearing env values.',
            metadata:    [
                             'sink' => $sink,
                         ],
        );
    }
}
