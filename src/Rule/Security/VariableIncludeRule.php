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
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Detects include and require paths built from variables.
 */
final class VariableIncludeRule implements RuleInterface
{
    /**
     * Stable rule identifier for variable include findings.
     */
    public const ID = 'security.variable-include';

    /**
     * Describe the variable include security rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Variable include or require path',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find include and require expressions using dynamic paths.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for variable include paths.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Include_::class) as $include) {
            if ($this->isFixedIncludeExpression($include->expr)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Variable include/require path detected.',
                filePath:    $analysisUnit->file->displayPath,
                line:        $include->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Use fixed include paths or map request values through an allow-list before loading files.',
            );
        }

        return $findings;
    }

    /**
     * Treat literal paths and paths derived only from magic constants as fixed bootstrap includes.
     *
     * @return bool True when the include path cannot vary from request or runtime data.
     */
    private function isFixedIncludeExpression(Expr $expression): bool
    {
        if (SecurityNodeHelper::isStringLiteral($expression) || $expression instanceof Scalar\MagicConst\Dir || $expression instanceof Scalar\MagicConst\File) {
            return true;
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            return $this->isFixedIncludeExpression($expression->left)
                && $this->isFixedIncludeExpression($expression->right);
        }

        if ($expression instanceof Expr\FuncCall && SecurityNodeHelper::globalFunctionName($expression) === 'dirname') {
            $path = SecurityNodeHelper::argumentValue($expression->args, 0);
            if (!$path instanceof Expr || !$this->isFixedIncludeExpression($path)) {
                return false;
            }

            $levels = SecurityNodeHelper::argumentValue($expression->args, 1);

            return $levels === null || $levels instanceof Scalar\Int_;
        }

        return false;
    }
}
