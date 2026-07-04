<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Modernisation;

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
use PhpParser\Node\Stmt;

/**
 * Flags a `switch` whose branches all return a value directly, since a PHP 8 `match` expression says the
 * same thing more compactly, so the user can consider tightening it up.
 *
 * Runs per file, but only on targets already on PHP 8.0+. It reports a switch of three or more cases when
 * every case is a single `return`. Advisory only: `match` uses strict comparison and is exhaustive, so
 * the rewrite is not always equivalent - gruff-php suggests, it never gates or rewrites.
 */
final readonly class MatchExpressionCandidateRule implements RuleInterface
{
    /**
     * Stable rule identifier for match expression candidate findings.
     */
    public const ID = 'modernisation.match-expression-candidate';

    /**
     * Describes the match-expression candidate rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, medium confidence).
     */
    public function definition(): RuleDefinition
    {
        // Advisory at medium confidence: match changes comparison and exhaustiveness, so it suggests, never gates.
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
     * Reports each switch whose direct-return branches could collapse into a PHP 8 match expression.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context supplying the target PHP version.
     *
     * @return list<Finding> - One finding per match-expression candidate; empty on pre-PHP-8 targets or when no switch qualifies.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.0)) {
            // The match expression needs PHP 8.0, so stay silent on targets that cannot use it.
            return [];
        }

        $findings = [];

        // Scan every switch statement in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Switch_::class) as $switch) {
            // A match is a clean fit only for a switch of three or more cases that each return directly.
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
     * Reports whether every switch case is exactly one return, so the body maps cleanly onto a match.
     *
     * @param Stmt\Switch_ $switch - Switch under inspection; only an all-direct-return body maps cleanly onto a match.
     *
     * @return bool - True when all cases return directly, false when any case falls through or does more work.
     */
    private function allCasesReturnDirectly(Stmt\Switch_ $switch): bool
    {
        // Check each case in the switch.
        foreach ($switch->cases as $case) {
            if (count($case->stmts) !== 1 || !$case->stmts[0] instanceof Stmt\Return_) {
                // Any case with fall-through or extra statements would not survive the rewrite, so reject.
                return false;
            }
        }

        // Every case is a single return, so the switch is a clean match candidate.
        return true;
    }
}
