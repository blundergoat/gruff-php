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
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Flags a direct read from a request/session superglobal (`$_GET`, `$_POST`, `$_SESSION`) in domain code,
 * so the user can route request state through a boundary abstraction instead of reaching for globals.
 *
 * Write-position superglobals (assignment targets and unset arguments) are skipped: they mutate request
 * state rather than read it, so only reads - including compound assignments, which read before writing -
 * report. Controller files are exempt as the sanctioned request-handling boundary.
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
     * Describes the forbidden-global-access rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, medium confidence).
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
     * Reports each direct superglobal read that sits outside a controller boundary.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per forbidden global read; empty in controller files or when none are read.
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

        // Scan every variable occurrence in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Variable::class) as $variable) {
            // Only a literal superglobal name ($_GET/$_POST/$_SESSION) is in scope here.
            if (!is_string($variable->name) || !in_array($variable->name, self::FORBIDDEN_GLOBALS, true)) {
                continue;
            }

            if (isset($writeTargets[spl_object_id($variable)])) {
                // Write position: the superglobal is being assigned or unset, not read.
                continue;
            }

            $key = $variable->name . ':' . $variable->getStartLine();
            // Report each superglobal at most once per line, even if it appears more than once.
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
     * Indexes the variables that appear in write position, so reads can be told apart from writes.
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

        // Every plain assignment target is a write, not a read.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assign) {
            $this->markWriteTarget($assign->var, $writtenVariableIds);
        }

        // Every unset argument is a write position too.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Unset_::class) as $unset) {
            // A single unset can clear several targets at once.
            foreach ($unset->vars as $target) {
                $this->markWriteTarget($target, $writtenVariableIds);
            }
        }

        return $writtenVariableIds;
    }

    /**
     * Records the base variable of one write target so a later read of it can be recognised.
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

        // Record the base variable once the dimension chain has been peeled away.
        if ($target instanceof Expr\Variable) {
            $writtenVariableIds[spl_object_id($target)] = true;
        }
    }

    /**
     * Reports whether a file path is treated as a controller boundary where superglobals are allowed.
     *
     * @param string $displayPath - File path as shown in findings; matched by convention to spot controller code.
     *
     * @return bool - True when direct request/session access is allowed for this path, false otherwise.
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
