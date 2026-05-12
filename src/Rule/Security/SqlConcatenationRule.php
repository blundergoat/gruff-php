<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;

/**
 * Detects SQL-like strings assembled through concatenation.
 */
final class SqlConcatenationRule implements RuleInterface
{
    /**
     * Stable rule identifier for SQL concatenation findings.
     */
    public const ID = 'security.sql-concatenation';

    /**
     * @var list<string>
     */
    private const QUERY_METHODS = ['exec', 'query', 'raw', 'select'];

    /**
     * Describe the SQL concatenation rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'SQL string concatenation',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find query method calls whose first argument uses concatenation or interpolation.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for heuristic SQL concatenation.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder   = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Expr\MethodCall::class) as $call) {
            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            if ($firstArg !== null && $this->isQueryMethod($call->name) && SecurityNodeHelper::containsConcatOrInterpolation($firstArg)) {
                $findings[] = $this->finding($unit, $call);
            }
        }

        foreach ($finder->findInstanceOf($unit->statements, Expr\StaticCall::class) as $call) {
            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            if ($firstArg !== null && $this->isQueryMethod($call->name) && SecurityNodeHelper::containsConcatOrInterpolation($firstArg)) {
                $findings[] = $this->finding($unit, $call);
            }
        }

        return $findings;
    }

    /**
     * Check whether a method name is one of the configured query entry points.
     *
     * @return bool True when the method is query-like.
     */
    private function isQueryMethod(Node $name): bool
    {
        return $name instanceof Identifier && in_array(strtolower($name->toString()), self::QUERY_METHODS, true);
    }

    /**
     * Build the SQL concatenation finding for a call node.
     *
     * @return Finding Security finding.
     */
    private function finding(AnalysisUnit $unit, Node $node): Finding
    {
        return new Finding(
            ruleId:      self::ID,
            message:     'Heuristic SQL query string concatenation detected.',
            filePath:    $unit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Use prepared statements, bound parameters, or query-builder parameter APIs instead of concatenating SQL.',
        );
    }
}
