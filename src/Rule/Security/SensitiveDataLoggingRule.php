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
     * @return RuleDefinition Rule metadata and defaults.
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
        );
    }

    /**
     * Find log sinks that include request or secret-like values.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for sensitive data logging.
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

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function loggerCallFindings(AnalysisUnit $analysisUnit, Expr\MethodCall|Expr\StaticCall $call): array
    {
        $method = SecurityNodeHelper::methodName($call);
        if ($method === null || !in_array($method, self::LOG_METHODS, true)) {
            return [];
        }

        if (!$this->hasSensitiveArgument($call->args)) {
            return [];
        }

        return [$this->finding($analysisUnit, $call, $method)];
    }

    /**
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args
     * @return bool True when any argument contains request or sensitive data.
     */
    private function hasSensitiveArgument(array $args): bool
    {
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }

            if (SecurityNodeHelper::containsUserInput($arg->value) || SecurityNodeHelper::containsSensitiveReference($arg->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the sensitive data logging finding.
     *
     * @return Finding Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $sink): Finding
    {
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
