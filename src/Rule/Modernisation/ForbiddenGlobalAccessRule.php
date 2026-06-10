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
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Detects direct reads from global request and environment arrays.
 *
 * Write-position superglobals (assignment targets and unset arguments) are
 * skipped: they mutate request state rather than read it, so only reads -
 * including compound assignments, which read before writing - report.
 */
final readonly class ForbiddenGlobalAccessRule implements RuleInterface
{
    /**
     * Stable rule identifier for forbidden global access findings.
     */
    public const ID = 'modernisation.forbidden-global-access';

    /**
     * @var list<string>
     */
    private const FORBIDDEN_GLOBALS = ['_GET', '_POST', '_SESSION'];

    /**
     * Describe the forbidden global access rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning at medium confidence: reading superglobals in domain code is a real leak, not a mere style smell.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Forbidden direct global access',
            pillar:          Pillar::Modernisation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find direct superglobal reads outside controller boundaries.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for forbidden global reads.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if ($this->isControllerPath($analysisUnit->file->displayPath)) {
            // Controllers are the sanctioned boundary for superglobals, so exempt the whole file there.
            return [];
        }

        $findings     = [];
        $seen         = [];
        $writeTargets = $this->writePositionVariableIds($analysisUnit);

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Variable::class) as $variable) {
            if (!is_string($variable->name) || !in_array($variable->name, self::FORBIDDEN_GLOBALS, true)) {
                continue;
            }

            if (isset($writeTargets[spl_object_id($variable)])) {
                // Write position: the superglobal is being assigned or unset, not read.
                continue;
            }

            $key = $variable->name . ':' . $variable->getStartLine();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('Direct access to $%s outside a controller boundary.', $variable->name),
                filePath:    $analysisUnit->file->displayPath,
                line:        $variable->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Modernisation,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Pass request/session data through a boundary abstraction instead of reading superglobals in domain code; gruff-php reports only.',
                metadata:    [
                    'global' => $variable->name,
                ],
            );
        }

        return $findings;
    }

    /**
     * Index variables that appear in write position across the unit.
     *
     * Plain assignment targets and unset arguments are writes; compound
     * assignments (`.=`, `+=`) are AssignOp nodes, stay out of this set, and
     * therefore keep reporting because they read the current value first.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose assignments and unsets are scanned.
     *
     * @return array<int, true> - spl_object_id set of base variables written by assignments or unsets.
     */
    private function writePositionVariableIds(AnalysisUnit $analysisUnit): array
    {
        $writtenVariableIds = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assign) {
            $this->markWriteTarget($assign->var, $writtenVariableIds);
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Unset_::class) as $unset) {
            foreach ($unset->vars as $target) {
                $this->markWriteTarget($target, $writtenVariableIds);
            }
        }

        return $writtenVariableIds;
    }

    /**
     * Record the base variable of one write target.
     *
     * @param Expr             $target - Assignment left-hand side or unset argument.
     * @param array<int, true> $writtenVariableIds - Output set keyed by spl_object_id, appended in place.
     *
     * @return void
     */
    private function markWriteTarget(Expr $target, array &$writtenVariableIds): void
    {
        // Follow only the var axis of dimension chains: expressions inside the dims are still reads.
        while ($target instanceof Expr\ArrayDimFetch) {
            $target = $target->var;
        }

        if ($target instanceof Expr\Variable) {
            $writtenVariableIds[spl_object_id($target)] = true;
        }
    }

    /**
     * Check whether a file path is treated as a controller boundary.
     *
     * @param string $displayPath - File path as shown in findings; matched by convention to spot controller code.
     *
     * @return bool - True when direct request/session access is allowed.
     */
    private function isControllerPath(string $displayPath): bool
    {
        $normalized = '/' . str_replace('\\', '/', $displayPath);

        // Treat a Controller/Controllers directory or a *Controller.php suffix as the request-handling boundary.
        return str_contains($normalized, '/Controller/')
            || str_contains($normalized, '/Controllers/')
            || str_ends_with($displayPath, 'Controller.php');
    }
}
