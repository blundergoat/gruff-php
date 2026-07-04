<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a docblock that lists only bare param/return tags with no summary and no tag descriptions, so the
 * user turns box-ticking documentation into something that states intent.
 *
 * Runs per file over documented function-likes. A docblock fires only when every line is a tag AND every
 * tag is a bare param/return with no prose after its type. Advisory, medium confidence - a tags-only block
 * can be deliberate on a trivial unit.
 */
final readonly class BarePhpdocTagsRule implements RuleInterface
{
    /**
     * Stable rule identifier for bare PHPDoc tag findings.
     */
    public const ID = 'docs.bare-phpdoc-tags';

    /**
     * Describes the bare-PHPDoc-tag rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, medium confidence).
     */
    public function definition(): RuleDefinition
    {
        // Advisory at medium confidence: a tags-only docblock can be deliberate on a trivial unit, so this nudges
        // toward describing intent rather than gating a build.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Bare PHPDoc tags',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Reports each docblock that only lists bare parameter or return tags.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for bare PHPDoc blocks.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        // Check every documented method and function in the file.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $docComment = $node->getDocComment();

            // An undocumented callable has no docblock to weigh.
            if ($docComment === null) {
                continue;
            }

            $docText  = $docComment->getText();
            $stripped = preg_replace('/\/\*\*|\*\/|\*/', '', $docText) ?? $docText;
            $stripped = trim($stripped);

            $lines = array_filter(
                array_map('trim', explode("\n", $stripped)),
                static fn(string $line): bool => $line !== '',
            );

            $hasNonTagContent = false;

            // Look for any line that is not a tag.
            foreach ($lines as $line) {
                if (!str_starts_with($line, '@')) {
                    $hasNonTagContent = true;

                    break;
                }
            }

            // A docblock that carries real prose already states intent.
            if ($hasNonTagContent) {
                continue;
            }

            // An empty docblock is nothing this rule reports.
            if ($lines === []) {
                continue;
            }

            $hasOnlyBareTags = true;

            // Every remaining line is a tag; check each one is a bare tag.
            foreach ($lines as $line) {
                if ($this->isBareParamOrReturnTag($line)) {
                    continue;
                }

                $hasOnlyBareTags = false;

                break;
            }

            // A described tag means the block is not bare, so leave it alone.
            if (!$hasOnlyBareTags) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s has PHPDoc tags but no descriptive summary or tag descriptions.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Add a short summary, or describe the parameter and return tags so the docblock states intent.',
            );
        }

        return $findings;
    }

    /**
     * Reports whether one PHPDoc tag has a type but no description.
     *
     * @param string $line - Single trimmed docblock line, already stripped of comment markers, to classify.
     *
     * @return bool - True when the tag is a bare parameter or return tag.
     */
    private function isBareParamOrReturnTag(string $line): bool
    {
        // Match @param tags that end at the variable name with no descriptive prose.
        if (preg_match('/^@param\s+\S+(?:\s+\S+)*\s+\$\w+\s*$/', $line) === 1) {
            // A @param stopping at the variable name carries no description, so it is bare.
            return true;
        }

        if (!str_starts_with($line, '@return ')) {
            // Lines that are neither a bare @param nor a @return cannot be a bare return tag.
            return false;
        }

        // A @return is bare exactly when no prose follows its type, so negate the shared description check.
        return !PhpdocTagText::hasReturnTagDescription(trim(substr($line, strlen('@return '))));
    }
}
