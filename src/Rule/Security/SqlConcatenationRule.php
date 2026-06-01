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
use PhpParser\Node\Identifier;

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
     * @return RuleDefinition - Rule metadata and defaults.
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
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for heuristic SQL concatenation.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        $calls = NodeIndex::nodesOfAny($analysisUnit, [Expr\MethodCall::class, Expr\StaticCall::class]);
        foreach ($calls as $call) {
            /** @var Expr\MethodCall|Expr\StaticCall $call NodeIndex query restricts these classes. */
            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            if ($firstArg !== null
                && $call->name instanceof Identifier
                && in_array(strtolower($call->name->toString()), self::QUERY_METHODS, true)
                && SecurityNodeHelper::containsConcatOrInterpolation($firstArg)
            ) {
                $findings[] = $this->finding($analysisUnit, $call);
            }
        }

        return $findings;
    }

    /**
     * Build the SQL concatenation finding for a call node.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path recorded on the finding.
     * @param Node         $node - Query call flagged as concatenating SQL; its start line locates the finding.
     *
     * @return Finding - Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node): Finding
    {
        // Heuristic match only, so emit a medium-confidence warning rather than a hard error.
        return new Finding(
            ruleId:      self::ID,
            message:     'Heuristic SQL query string concatenation detected.',
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Use prepared statements, bound parameters, or query-builder parameter APIs instead of concatenating SQL.',
        );
    }
}
