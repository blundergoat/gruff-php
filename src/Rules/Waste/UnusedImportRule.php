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
     * Reports each import alias that never appears once its own `use` line is blanked out.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per import alias unused after its own import line is ignored.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $uses       = NodeIndex::nodesOf($analysisUnit, Use_::class);

        // No imports to check, so skip the source-text scan entirely rather than blank a file with no use lines.
        if ($uses === []) {
            return [];
        }

        /** @var list<Use_> $useStatements NodeFinder returns only use statement nodes for this query. */
        $useStatements     = $uses;
        $sourceWithoutUses = $this->removeUseStatements($analysisUnit->source, $useStatements);
        $findings          = [];

        // Check every import statement in the file.
        foreach ($useStatements as $use) {
            // A single `use` line can import several names.
            foreach ($use->uses as $useUse) {
                $alias = $useUse->getAlias()->toString();

                // Search for the import alias as a whole token outside the use statement itself.
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
     * Lines are replaced with empty strings rather than removed so every other
     * line keeps its original 1-based number for any later position lookup.
     *
     * @param string     $source - Full source text of the unit, used only as the haystack to blank and scan.
     * @param list<Use_> $uses - Import statements whose line spans must be erased before the alias search.
     *
     * @return string - Source text with each import statement's lines replaced by empty strings.
     */
    private function removeUseStatements(string $source, array $uses): string
    {
        $lines = explode("\n", $source);

        // Erase the line span of each import.
        foreach ($uses as $use) {
            $startLine = $use->getStartLine();
            $endLine   = $use->getEndLine();

            // Only blank a real, positioned line span.
            if ($startLine > 0 && $endLine > 0) {
                for ($i = $startLine - 1; $i < $endLine; $i++) {
                    // Guard against a line index past the end of the source.
                    if (isset($lines[$i])) {
                        $lines[$i] = '';
                    }
                }
            }
        }

        // Re-join with "\n" so line offsets are preserved; the result is searched, never written back to disk.
        return implode("\n", $lines);
    }

}
