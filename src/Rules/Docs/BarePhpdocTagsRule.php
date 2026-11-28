<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\FunctionComplexity;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags methods and named functions whose PHPDoc contains only bare param/return tags, with no descriptive prose.
 * A block qualifies when every non-empty content line is such a tag and none has a description.
 *
 * Closures are not scanned; the rule stays advisory because type-only documentation can be deliberate.
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
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, high confidence).
     */
    public function definition(): RuleDefinition
    {
        // Advisory: a tags-only docblock can be deliberate on a trivial unit, so this nudges
        // toward describing intent rather than gating a build.
        return new RuleDefinition(
            id:                  self::ID,
            name:                'Bare PHPDoc tags',
            pillar:              Pillar::Documentation,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Advisory,
            confidence:          Confidence::High,
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
     * @param RuleContext  $ruleContext  - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for bare PHPDoc blocks.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];


        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node - NodeIndex restricts results to methods and named functions. */
            $docComment = $node->getDocComment();


            if ($docComment === null) {
                continue;
            }

            // Prose, a described tag, another tag, or an empty block means the docblock is not bare.
            if (!PhpdocTagText::isBareTagsOnly($docComment->getText())) {
                continue;
            }

            $symbol = FunctionComplexity::resolveSymbol($node);

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
