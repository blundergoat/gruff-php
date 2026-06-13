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
use PhpParser\Node\Stmt;

/**
 * Detects catch blocks that swallow exceptions without handling or reporting them.
 */
final class SilentCatchRule implements RuleInterface
{
    /**
     * Stable rule identifier for silent catch findings.
     */
    public const ID = 'security.silent-catch';

    /**
     * Describe the silent catch rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Silent catch block',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find catch blocks that only contain no-op statements.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for swallowed exceptions.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Catch_::class) as $catch) {
            if (!$this->isSilent($catch)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Catch block swallows exceptions without handling or reporting them.',
                filePath:    $analysisUnit->file->displayPath,
                line:        $catch->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                remediation: 'Log, rethrow, convert, or otherwise handle caught exceptions explicitly.',
            );
        }

        return $findings;
    }

    /**
     * Check whether a catch block has no executable handling statements.
     *
     * @param Stmt\Catch_ $catch - Parsed catch block whose body statements are inspected for any real handling.
     *
     * @return bool - True when the catch body is silent.
     */
    private function isSilent(Stmt\Catch_ $catch): bool
    {
        foreach ($catch->stmts as $statement) {
            if (!$statement instanceof Stmt\Nop) {
                // A non-Nop statement is real handling, so the catch is not silent.
                return false;
            }
        }

        // Only Nop placeholders remain, so the exception is caught and dropped without action.
        return true;
    }
}
