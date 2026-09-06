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
use GruffPhp\Support\DeclarationLine;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a documented method or function that omits an explicit `@return` tag, so the user gets a complete
 * contract - every documented callable states what it hands back, even when that is void or never.
 *
 * Runs per file over documented function-likes, skipping constructors and destructors (which have no
 * meaningful return). Advisory, high confidence. The companion return-comment rule then checks the tag
 * actually describes the value rather than restating the type.
 */
final readonly class MissingReturnTagRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing @return tag findings.
     */
    public const ID = 'docs.missing-return-tag';

    /**
     * Describes the missing `@return` tag rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, high confidence).
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
     * Reports each documented function-like declaration that lacks an `@return` tag.
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

        // Check every method and function in the file.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            // A constructor or destructor has no meaningful return to document.
            if ($node instanceof ClassMethod && in_array($node->name->toString(), ['__construct', '__destruct'], true)) {
                continue;
            }

            $docComment = $node->getDocComment();

            // An undocumented callable is out of scope; a separate rule covers missing docblocks.
            if ($docComment === null) {
                continue;
            }

            $docText = $docComment->getText();

            // A docblock that already declares its return contract is fine.
            if (str_contains($docText, '@return')) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s has a docblock but needs an @return tag with a brief description (one plain-English clause; not a restatement of the type signature).', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        DeclarationLine::of($node),
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
