<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Waste;

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
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;

/**
 * Flags an import whose alias is never referenced anywhere but the `use` line itself, so the user can
 * drop dead imports that clutter the top of a file.
 *
 * Runs per file: it blanks out the import lines, then searches the rest of the source for each alias as a
 * whole token. An alias with no match is reported at warning, since an unreferenced import is almost
 * always dead code.
 */
final readonly class UnusedImportRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused import findings.
     */
    public const ID = 'waste.unused-import';

    /**
     * Describes the unused-import rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning, not advisory: an unreferenced import is almost always dead code a reviewer should drop.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unused import',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports each alias from a non-group use statement that never appears once those statements' lines are blanked out.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose AST supplies use statements and whose source is searched for alias references.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per such alias with no whole-word match in the remaining source.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $uses       = NodeIndex::nodesOf($analysisUnit, Use_::class);


        if ($uses === []) {
            return [];
        }

        /** @var list<Use_> $useStatements - NodeFinder-compatible Use_ nodes returned by the shared index for source-line removal. */
        $useStatements     = $uses;
        $sourceWithoutUses = $this->removeUseStatements($analysisUnit->source, $useStatements);
        $findings          = [];


        foreach ($useStatements as $use) {
            foreach ($use->uses as $useUse) {
                $alias = $useUse->getAlias()->toString();

                // Require a whole-word alias so longer identifiers do not count, after removing declarations that would otherwise match themselves.
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/', $sourceWithoutUses) === 1) {
                    continue;
                }

                $fullName = $useUse->name->toString();

                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     sprintf('Import %s is unused.', $fullName),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $use->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $alias,
                    remediation: 'Remove the unused import statement.',
                );
            }
        }

        return $findings;
    }

    /**
     * Blanks out the source lines occupied by imports so the alias search cannot match an import's own line.
     *
     * Empty line replacements preserve the original line numbers of surviving source text.
     *
     * @param string     $source - Full source text of the unit, used only as the haystack to blank and scan.
     * @param list<Use_> $uses - Import statements whose positively positioned line spans are blanked before the alias search.
     *
     * @return string - Source with positively positioned import line spans blanked while retaining the original line count.
     */
    private function removeUseStatements(string $source, array $uses): string
    {
        $lines = explode("\n", $source);


        foreach ($uses as $use) {
            $startLine = $use->getStartLine();
            $endLine   = $use->getEndLine();

            // Import nodes without positive source positions leave the search text unchanged.
            if ($startLine > 0 && $endLine > 0) {
                for ($i = $startLine - 1; $i < $endLine; $i++) {

                    if (isset($lines[$i])) {
                        $lines[$i] = '';
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

}
