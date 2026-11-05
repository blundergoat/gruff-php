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
            id:                  self::ID,
            name:                'Bare PHPDoc tags',
            pillar:              Pillar::Documentation,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Advisory,
            confidence:          Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape' => 'A docblock that exists only to carry precise @param/@return types a static analyser requires, with no prose anywhere.',
                    'mitigation' => 'The types stay as they are; add a one-line summary, or describe a single tag, so the block also states intent.',
                ],
            ],
        );
    }

    /**
     * Reports each docblock that only lists bare parameter or return tags.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
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

            // Prose, a described tag, another tag, or an empty block means the docblock is not bare.
            if (!PhpdocTagText::isBareTagsOnly($docComment->getText())) {
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

}
