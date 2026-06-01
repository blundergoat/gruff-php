<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Detects logging or dumping of request-controlled or sensitive-looking data.
 */
final class SensitiveDataLoggingRule implements RuleInterface
{
    /**
     * Stable rule identifier for sensitive data logging.
     */
    public const ID = 'security.sensitive-data-logging';

    /**
     * @var list<string>
     */
    private const LOG_FUNCTIONS = ['error_log', 'print_r', 'var_dump'];

    /**
     * @var list<string>
     */
    private const LOG_METHODS = ['alert', 'critical', 'debug', 'emergency', 'error', 'info', 'log', 'notice', 'warning'];

    /**
     * Describe the sensitive data logging rule.
     *
     * @return RuleDefinition - identity, pillar, tier, and default severity/confidence the registry
     *   uses to list and configure this rule
     */
    public function definition(): RuleDefinition
    {
        // Hand back the static metadata the registry uses to list and configure this rule.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Sensitive data logging',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find log sinks that include request or secret-like values.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per flagged log sink across function, method, and static
     *   calls; empty when nothing leaks request or secret data
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if ($name === null || !in_array($name, self::LOG_FUNCTIONS, true)) {
                continue;
            }

            if ($this->hasSensitiveArgument($call->args)) {
                $findings[] = $this->finding($analysisUnit, $call, $name);
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\MethodCall::class) as $call) {
            array_push($findings, ...$this->loggerCallFindings($analysisUnit, $call));
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $call) {
            array_push($findings, ...$this->loggerCallFindings($analysisUnit, $call));
        }

        // Hand back every sensitive-logging finding gathered across function, method, and static log sinks.
        return $findings;
    }

    /**
     * Build logger call findings for the security rule.
     *
     * @param AnalysisUnit                    $analysisUnit Unit being scanned; supplies the display path for findings.
     * @param Expr\MethodCall|Expr\StaticCall $call         Possible logger call whose name and arguments are checked.
     *
     * @return list<Finding> - zero or one finding; empty when the call is not a tracked logger method or carries no sensitive argument
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

        // At most one finding: a single logger call is one sink, flagged once.
        return [$this->finding($analysisUnit, $call, $method)];
    }

    /**
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args
     *
     * @return bool - true on the first argument carrying request-tainted or secret-bearing data;
     *   false when every argument is static or non-sensitive
     */
    private function hasSensitiveArgument(array $args): bool
    {
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }

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
     * Detect logger arguments that contain only static message/context values.
     *
     * @param Expr $expr Argument expression to classify; recursed into for arrays and concatenations.
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

        if ($expr instanceof Expr\Array_) {
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
     * Build the sensitive data logging finding.
     *
     * @param AnalysisUnit $analysisUnit Unit being scanned; supplies the display path recorded on the finding.
     * @param Node         $node         Log call flagged as leaking; its start line locates the finding.
     * @param string       $sink         Name of the log function or method, surfaced in the message and metadata.
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
