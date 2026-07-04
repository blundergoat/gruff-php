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
 * Flags a catch block that swallows its exception without logging, rethrowing, or otherwise acting on it, so
 * the user does not lose a failure to a silent empty handler.
 *
 * Runs per file over every catch clause, treating a body of only no-op placeholders as silent. Warning,
 * high confidence.
 */
final class SilentCatchRule implements RuleInterface
{
    /**
     * Stable rule identifier for silent catch findings.
     */
    public const ID = 'security.silent-catch';

    /**
     * Describes the silent-catch rule for the registry and reports.
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
     * Reports each catch block that swallows the exception without acting on it.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for swallowed exceptions.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every catch block in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Catch_::class) as $catch) {
            // A catch that does real work is fine.
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
     * Reports whether a catch block has no executable handling statements.
     *
     * @param Stmt\Catch_ $catch - Parsed catch block whose body statements are inspected for any real handling.
     *
     * @return bool - True when the catch body is silent.
     */
    private function isSilent(Stmt\Catch_ $catch): bool
    {
        // Weigh each statement in the catch body.
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
