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
 * Detects documented methods that omit an explicit @return contract.
 */
final readonly class MissingReturnTagRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing @return tag findings.
     */
    public const ID = 'docs.missing-return-tag';

    /**
     * Describe the missing @return tag rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing @return tag',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            description:     'Every documented method must declare its return contract with an @return tag, including methods declared void or never. Constructors and destructors are exempt.',
        );
    }

    /**
     * Find documented function-like declarations that lack an @return tag.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for missing return tags.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            // User view: choose the findings list branch for this case.
            if ($node instanceof ClassMethod && in_array($node->name->toString(), ['__construct', '__destruct'], true)) {
                continue;
            }

            $docComment = $node->getDocComment();

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($docComment === null) {
                continue;
            }

            $docText = $docComment->getText();

            // User view: choose the findings list branch for this case.
            if (str_contains($docText, '@return')) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s has a docblock but needs an @return tag with a brief description (one plain-English clause; not a restatement of the type signature).', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Add an `@return SomeType Description.` tag. This rule wants content, not boilerplate - the description should answer "what does the returned value represent at the edge cases."',
            );
        }

        return $findings;
    }

}
