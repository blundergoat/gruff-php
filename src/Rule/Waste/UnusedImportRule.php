<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Waste;

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
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;

/**
 * Detects import statements whose alias is not referenced outside the import list.
 */
final readonly class UnusedImportRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused import findings.
     */
    public const ID = 'waste.unused-import';

    /**
     * Describe the unused import rule.
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
     * Find imported names that are not referenced after import declarations are removed.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unused import statements.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $uses       = NodeIndex::nodesOf($analysisUnit, Use_::class);

        if ($uses === []) {
            // No imports to check, so skip the source-text scan entirely rather than blank a file with no use lines.
            return [];
        }

        /** @var list<Use_> $useStatements NodeFinder returns only use statement nodes for this query. */
        $useStatements     = $uses;
        $sourceWithoutUses = $this->removeUseStatements($analysisUnit->source, $useStatements);
        $findings          = [];

        foreach ($useStatements as $use) {
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

        // One finding per alias whose token never appears once its own import line is blanked out.
        return $findings;
    }

    /**
     * Blank out the source lines occupied by import declarations so the alias search cannot match an
     * import against its own `use` statement. Lines are replaced with empty strings rather than removed
     * so every other line keeps its original 1-based number for any later position lookup.
     *
     * @param string     $source - Full source text of the unit, used only as the haystack to blank and scan.
     * @param list<Use_> $uses - Import statements whose line spans must be erased before the alias search.
     *
     * @return string - Source text with each import statement's lines replaced by empty strings.
     */
    private function removeUseStatements(string $source, array $uses): string
    {
        $lines = explode("\n", $source);

        foreach ($uses as $use) {
            $startLine = $use->getStartLine();
            $endLine   = $use->getEndLine();

            if ($startLine > 0 && $endLine > 0) {
                for ($i = $startLine - 1; $i < $endLine; $i++) {
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
