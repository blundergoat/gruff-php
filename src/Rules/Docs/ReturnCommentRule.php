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
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

/**
 * Flags a value-returning function or method whose `@return` tag states a type but no description.
 *
 * The result's contract belongs in the `@return` tag, where PHPDoc consumers and IDEs already read
 * it and where it is the verification surface a reviewer diffs against the body. This rule fills the
 * gap its sibling docs rules leave: a value-returner with a summary plus a bare `@return Type` trips
 * neither docs.missing-return-tag (presence is satisfied) nor docs.bare-phpdoc-tags (the docblock is
 * not tags-only). It stays silent when there is no docblock or no `@return` tag, and exempts void or
 * never returns and constructors/destructors, which have no result to describe. Advisory.
 */
final readonly class ReturnCommentRule implements RuleInterface
{
    /**
     * Stable rule identifier for described-return-tag findings. Unchanged from the rule's earlier
     * shape so adopter configs and baselines keep working; only its meaning changed.
     */
    public const ID = 'docs.return-comment';

    /**
     * Describe the described-return-tag documentation rule.
     *
     * @return RuleDefinition - rule metadata and defaults for the registry and listings
     */
    public function definition(): RuleDefinition
    {
        // High confidence: an @return tag with no prose after the type is unambiguous; advisory so teams opt in.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Described return tag',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            description:     'A value-returning function or method must describe its result in its @return tag, not just restate the type, so a reviewer can diff the documented contract against the body. Fires only when an @return tag is present but carries no description; missing docblocks and missing @return tags are owned by docs.missing-public-phpdoc and docs.missing-return-tag, and a wholly tags-only docblock by docs.bare-phpdoc-tags. Void/never returns and constructors/destructors are exempt. Advisory by default; opt in to stricter enforcement via .gruff-php.yaml.',
        );
    }

    /**
     * Find value-returning function-likes whose `@return` tag is present but undescribed.
     *
     * @param AnalysisUnit $unit    - parsed unit to inspect
     * @param RuleContext  $context - rule context for this analysis pass
     *
     * @return list<Finding> - one finding per value-returning declaration with a bare `@return` tag
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($unit, [ClassMethod::class, Function_::class]);
        $findings   = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            if (!$this->isValueReturning($node)) {
                continue;
            }

            $docComment = $node->getDocComment();

            if ($docComment === null) {
                // No docblock: docs.missing-public-phpdoc owns the gap, so stay silent to avoid double-reporting.
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

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

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
     * Decide whether a function-like declaration returns a value whose contract needs describing.
     *
     * A declared return type other than void/never counts; constructors and destructors never do
     * (mirroring MissingReturnTagRule). When no return type is declared, fall back to whether the
     * body actually returns an expression.
     *
     * @param ClassMethod|Function_ $node - function-like declaration to classify
     *
     * @return bool - true when the declaration yields a value an @return tag should describe
     */
    private function isValueReturning(ClassMethod|Function_ $node): bool
    {
        if ($node instanceof ClassMethod && in_array($node->name->toString(), ['__construct', '__destruct'], true)) {
            // Constructors and destructors have no return contract to describe.
            return false;
        }

        $returnType = $node->getReturnType();

        if ($returnType instanceof Identifier) {
            $typeName = strtolower($returnType->name);

            // void/never declare "no value", so there is nothing for an @return description to cover.
            return $typeName !== 'void' && $typeName !== 'never';
        }

        if ($returnType !== null) {
            // Any other declared shape (named, nullable, union, intersection) carries a value.
            return true;
        }

        // Untyped declaration: the only remaining signal is whether the body returns an expression.
        return $this->hasReturnWithValue($node);
    }

    /**
     * Detect at least one `return <expr>;` inside a function-like body.
     *
     * @param ClassMethod|Function_ $node - function-like declaration whose body is scanned
     *
     * @return bool - true when any return statement carries an expression
     */
    private function hasReturnWithValue(ClassMethod|Function_ $node): bool
    {
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf((array)$node->stmts, Return_::class) as $return) {
            /** @var Return_ $return Finder predicate restricts results to return statements. */
            if ($return->expr !== null) {
                return true;
            }
        }

        return false;
    }
}
