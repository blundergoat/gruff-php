<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Modernisation;

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
use PhpParser\Node\Stmt;

/**
 * Detects switch statements that can be expressed more directly as match expressions.
 */
final readonly class MatchExpressionCandidateRule implements RuleInterface
{
    /**
     * Stable rule identifier for match expression candidate findings.
     */
    public const ID = 'modernisation.match-expression-candidate';

    /**
     * Describe the match-expression candidate rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Match expression candidate',
            pillar:          Pillar::Modernisation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find switch statements whose direct-return branches may become match expressions.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for PHP 8 match-expression candidates.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.0)) {
            return [];
        }

        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Switch_::class) as $switch) {
            if (count($switch->cases) < 3 || !$this->allCasesReturnDirectly($switch)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Switch with direct return branches may be a PHP 8 match expression candidate.',
                filePath:    $analysisUnit->file->displayPath,
                line:        $switch->getStartLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::Modernisation,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Consider match only when strict comparison semantics and exhaustiveness are safe for this switch; gruff-php reports only.',
                metadata:    [
                    'requiresPhp' => 8.0,
                    'cases' => count($switch->cases),
                ],
            );
        }

        return $findings;
    }

    /**
     * Check whether every switch case consists of exactly one return statement.
     *
     * @return bool True when all cases return directly.
     */
    private function allCasesReturnDirectly(Stmt\Switch_ $switch): bool
    {
        foreach ($switch->cases as $case) {
            if (count($case->stmts) !== 1 || !$case->stmts[0] instanceof Stmt\Return_) {
                return false;
            }
        }

        return true;
    }
}
