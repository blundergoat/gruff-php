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
use GruffPhp\Support\DeclarationLine;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Flags documented methods and named functions without an explicit @return tag, including void and never returns.
 * Constructors and destructors are exempt.
 *
 * The first matching same-file direct ancestor method exempts an override if it has @return or a valid inheritance marker.
 * When no such method is found, a valid marker on the current method supplies the exemption.
 * Advisory severity and high confidence apply; docs.return-comment checks descriptions separately.
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
     * Reports documented functions and methods other than constructors and destructors that lack a local or inherited `@return` contract.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for missing return tags.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);
        $nodeFinder = new NodeFinder();
        $inheritanceHelper = new DocsInheritanceHelper();

        $findings = [];


        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node - NodeIndex limits this scan to methods and named functions. */
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


            if (str_contains($docText, '@return')) {
                continue;
            }

            // An inherited contract that already declares the return owes no second tag here.
            if ($node instanceof ClassMethod && $this->isReturnInherited($node, $analysisUnit->statements, $nodeFinder, $inheritanceHelper)) {
                continue;
            }

            $symbol = FunctionComplexity::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s has a docblock but no @return tag declaring its return contract.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        DeclarationLine::of($node),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Add an `@return SomeType` tag. Whether it needs a description is docs.return-comment\'s check, not this one.',
            );
        }

        return $findings;
    }

    /**
     * Reports whether a same-file ancestor or an inheritance marker supplies a method's return contract.
     *
     * @param ClassMethod $classMethod - Documented method without its own @return tag.
     * @param list<Node\Stmt> $statements - Unit statements searched for a direct ancestor method.
     * @param NodeFinder $nodeFinder - Shared finder reused across nodes.
     * @param DocsInheritanceHelper $inheritanceHelper - Resolves same-file ancestors and inheritance markers.
     *
     * @return bool - True for an ancestor's @return or valid marker, or the method's valid marker when no ancestor method is found.
     */
    private function isReturnInherited(
        ClassMethod $classMethod,
        array $statements,
        NodeFinder $nodeFinder,
        DocsInheritanceHelper $inheritanceHelper,
    ): bool {
        $ancestorMethod = $inheritanceHelper->sameFileAncestorMethod($classMethod, $statements, $nodeFinder);
        if ($ancestorMethod instanceof ClassMethod) {
            // A visible ancestor stands in only when it declares the return, or inherits it in turn through its own
            // marker; one hop, so a malformed cyclic `extends` cannot recurse.
            return str_contains($ancestorMethod->getDocComment()?->getText() ?? '', '@return')
                || $inheritanceHelper->hasInheritanceMarker($ancestorMethod);
        }

        return $inheritanceHelper->hasInheritanceMarker($classMethod);
    }
}
