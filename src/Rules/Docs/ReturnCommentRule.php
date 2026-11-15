<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

/**
 * Flags a value-returning callable whose @return tag states a type without describing the result.
 * Missing docblocks and return tags belong to other rules; void/never returns and constructors/destructors are exempt.
 *
 * Bare parameter/return-only blocks on named functions and methods belong to docs.bare-phpdoc-tags.
 * With everyCallable enabled, bound closures and arrow functions are also checked, including their bare-tag-only blocks.
 * Findings are advisory with high confidence.
 */
final readonly class ReturnCommentRule implements RuleInterface
{
    /** Stable rule identifier retained for existing configuration and baseline entries. */
    public const ID = 'docs.return-comment';

    /**
     * Describes the described-return-tag documentation rule for the registry and reports.
     *
     * @return RuleDefinition - rule metadata and defaults for the registry and listings
     */
    public function definition(): RuleDefinition
    {
        // For a value-returning callable eligible for this rule, an @return tag with no prose after its type is a documentation gap.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Described return tag',
            pillar:             Pillar::Documentation,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::High,
            defaultOptions:     [DocumentedCallables::EVERY_CALLABLE => true],
            optionDescriptions: [DocumentedCallables::EVERY_CALLABLE => DocumentedCallables::EVERY_CALLABLE_DESCRIPTION],
            description:        'A value-returning function or method must describe its result in its @return tag, not just restate the type, so a reviewer can diff the documented contract against the body. Fires only when an @return tag is present but carries no description; missing docblocks and missing @return tags are owned by docs.missing-phpdoc and docs.missing-return-tag, and a function or method docblock made only of bare @param and @return tags, with no summary or other tag, by docs.bare-phpdoc-tags. With `everyCallable`, also closures and arrow functions bound to a name, read from the docblock above the assignment, bare-tags-only blocks included. Void/never returns and constructors/destructors are exempt. Advisory by default; opt in to stricter enforcement via .gruff-php.yaml.',
        );
    }

    /**
     * Reports undescribed return tags after excluding non-value callables and delegated bare-tag blocks.
     *
     * @param AnalysisUnit $unit    - parsed unit to inspect
     * @param RuleContext  $context - rule context for this analysis pass
     *
     * @return list<Finding> - One finding per eligible callable with an undescribed return tag; empty when none qualify.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $findings   = [];


        foreach (DocumentedCallables::inUnit($unit, $context, $definition) as ['node' => $node, 'doc' => $docComment, 'symbol' => $symbol]) {

            if (!$this->isValueReturning($node)) {
                continue;
            }

            if ($docComment === null) {
                // No docblock: docs.missing-phpdoc owns the gap, so stay silent to avoid double-reporting.
                continue;
            }

            // Leave blocks containing only bare parameter and return tags on named functions and methods to docs.bare-phpdoc-tags.
            // Bound closures and arrow functions remain eligible because docs.bare-phpdoc-tags does not inspect them.
            if (!($node instanceof Closure || $node instanceof ArrowFunction) && PhpdocTagText::isBareTagsOnly($docComment->getText())) {
                continue;
            }

            $returnBody = PhpdocTagText::returnTagBody($docComment->getText());

            if ($returnBody === null) {
                // No @return tag: docs.missing-return-tag owns presence, so stay silent.
                continue;
            }


            if (PhpdocTagText::hasReturnTagDescription($returnBody)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s has a bare @return tag; describe what the returned value represents, not just its type.', $symbol),
                filePath:    $unit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Describe the result after the @return type, e.g. `@return Foo - parsed result; null when the lookup misses`, so the contract states what the value means at the edges.',
            );
        }

        return $findings;
    }

    /**
     * Classifies whether this rule requires a declaration's @return contract to describe a value.
     * Constructors, destructors, void, and never are excluded; an untyped body qualifies when it contains a return expression.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - function-like declaration to classify
     *
     * @return bool - True when this rule requires the declaration's @return tag to describe a value.
     */
    public function isValueReturning(ClassMethod|Function_|Closure|ArrowFunction $node): bool
    {
        if ($node instanceof ClassMethod && in_array($node->name->toString(), ['__construct', '__destruct'], true)) {
            // Constructors and destructors have no return contract to describe.
            return false;
        }

        $returnType = $node->getReturnType();

        // A built-in return type decides the classification directly; void and never are excluded.
        if ($returnType instanceof Identifier) {
            $typeName = strtolower($returnType->name);

            return $typeName !== 'void' && $typeName !== 'never';
        }

        if ($returnType !== null) {
            // Any other declared type is classified as value-returning for this rule.
            return true;
        }

        // For an untyped declaration, the remaining signal is a return statement with an expression.
        return $this->hasReturnWithValue($node);
    }

    /**
     * Reports whether a function-like body has at least one return statement carrying an expression.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - function-like declaration whose body is scanned
     *
     * @return bool - true when any return statement carries an expression
     */
    private function hasReturnWithValue(ClassMethod|Function_|Closure|ArrowFunction $node): bool
    {
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf((array)$node->getStmts(), Return_::class) as $return) {
            /** @var Return_ $return - Finder predicate restricts results to return statements. */
            // Any return with an expression counts; reachability is not checked.
            if ($return->expr !== null) {
                return true;
            }
        }

        return false;
    }
}
