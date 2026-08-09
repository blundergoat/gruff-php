<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Shared\PhysicalCommentAttachment;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;

/**
 * Flags a declared property that has no local documentation, so the user records what each field holds and
 * the invariant it maintains.
 *
 * Runs per file over classes, traits, interfaces, and enums. Each property declaration needs a docblock
 * unless the opt-in line-comment setting finds meaningful prose physically attached above its statement.
 * Advisory, medium confidence - enforcement is opt-in and documenting trivial properties is team-dependent.
 */
final readonly class MissingPropertyPhpdocRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing property PHPDoc findings.
     */
    public const ID = 'docs.missing-property-phpdoc';

    /** Property-specific filler that cannot document a declaration by itself. */
    private const PROPERTY_COMMENT_FILLER_WORDS = [
        'data' => true,
        'default' => true,
        'defaults' => true,
        'field' => true,
        'fields' => true,
        'properties' => true,
        'property' => true,
        'value' => true,
        'values' => true,
    ];

    /**
     * Describes the missing-property-PHPDoc rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, medium confidence).
     */
    public function definition(): RuleDefinition
    {
        // Advisory by default so property-doc enforcement is opt-in; Medium confidence because a missing
        // local docblock is unambiguous but the value of documenting trivial properties is team-dependent.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Missing property PHPDoc',
            pillar:             Pillar::Documentation,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::Medium,
            defaultOptions:     ['acceptLineComments' => false],
            description:        'Requires declared properties to explain their purpose with PHPDoc; an opt-in toggle also accepts meaningful attached line comments.',
            optionDescriptions: [
                'acceptLineComments' => 'When true, a physically attached // or # comment with meaning beyond the property name satisfies the rule.',
            ],
        );
    }

    /**
     * Reports each declared property that lacks local documentation.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for undocumented properties.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition               = $this->definition();
        $findings                 = [];
        $shouldAcceptLineComments = $ruleContext->settingsFor($definition)->option('acceptLineComments') === true;

        // Check every class-like declaration in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, ClassLike::class) as $classLike) {
            // Only a named class, trait, interface, or enum can own properties.
            if (!$this->isSupportedClassLike($classLike) || $classLike->name === null) {
                continue;
            }

            array_push(
                $findings,
                ...$this->declaredPropertyFindings(
                    $classLike,
                    $classLike->name->toString(),
                    $definition,
                    $analysisUnit,
                    $shouldAcceptLineComments,
                ),
            );
        }

        return $findings;
    }

    /**
     * Reports whether a class-like node can own declared properties.
     *
     * @param ClassLike $classLike - class-like node from the parsed unit; the caller must still
     *                             guard `$classLike->name` separately, since anonymous classes are supported but unnamed.
     *
     * @return bool - True when the node should be inspected.
     */
    private function isSupportedClassLike(ClassLike $classLike): bool
    {
        // Only these four kinds can declare properties; other ClassLike subtypes (none today, but future parser
        // additions) are skipped rather than mis-inspected.
        return $classLike instanceof Class_
            || $classLike instanceof Trait_
            || $classLike instanceof Interface_
            || $classLike instanceof Enum_;
    }

    /**
     * Finds the declared properties that carry no docblock.
     *
     * @param ClassLike      $classLike                - node whose `getProperties()` declarations are scanned; a
     *                                                 docblock on the `Property` statement (not the individual prop) is what suppresses the finding.
     * @param string         $className                - short class name the caller already resolved, used to build
     *                                                 the `Class::$prop` symbol; passed in so this method does not re-null-check `$classLike->name`.
     * @param RuleDefinition $definition               - this rule's metadata, copied into each emitted finding so
     *                                                 severity and pillar stay consistent without re-deriving them per property.
     * @param AnalysisUnit   $analysisUnit             - parsed unit supplying the display path recorded on findings.
     * @param bool           $shouldAcceptLineComments - Whether meaningful attached line comments satisfy the rule.
     *
     * @return list<Finding> - Findings for undocumented declared properties.
     */
    private function declaredPropertyFindings(
        ClassLike $classLike,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        bool $shouldAcceptLineComments,
    ): array {
        $findings = [];

        // Check each declared property for a docblock.
        foreach ($classLike->getProperties() as $property) {
            // PHPDoc always covers a declaration; meaningful line comments do so only under the opt-in toggle.
            if (
                $property->getDocComment() !== null
                || ($shouldAcceptLineComments && $this->hasMeaningfulAttachedLineComment($property, $analysisUnit->source))
            ) {
                continue;
            }

            // One declaration can name several properties, so report each.
            foreach ($property->props as $propertyProperty) {
                $findings[] = $this->declaredPropertyFinding(
                    propertyName: $propertyProperty->name->toString(),
                    className:    $className,
                    line:         $property->getStartLine(),
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                );
            }
        }

        return $findings;
    }

    /**
     * Reports whether an own-line comment attached to a property carries meaning beyond its declaration.
     *
     * @param Property $property - Property statement whose leading comments are inspected.
     * @param string   $source   - Whole-file source used to reject detached and trailing comments.
     *
     * @return bool - True when an attached // or # comment meaningfully documents the property.
     */
    private function hasMeaningfulAttachedLineComment(Property $property, string $source): bool
    {
        foreach ($property->getComments() as $comment) {
            if (
                $comment instanceof Doc
                || !$this->isLineComment($comment)
                || !PhysicalCommentAttachment::isOwnLineImmediatelyAbove($comment, $property, $source)
            ) {
                continue;
            }

            if ($this->isMeaningfulPropertyComment($comment->getText(), $property)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a parser comment uses a PHP line-comment delimiter.
     *
     * @param Comment $comment - Non-doc comment candidate.
     *
     * @return bool - True for // and # comments; block comments are false.
     */
    private function isLineComment(Comment $comment): bool
    {
        $text = ltrim($comment->getText());

        return str_starts_with($text, '//') || str_starts_with($text, '#');
    }

    /**
     * Reports whether line-comment prose explains more than a generic or repeated property label.
     *
     * @param string   $rawText  - Raw parser comment text including its delimiter.
     * @param Property $property - Property declaration whose names are checked for restatement.
     *
     * @return bool - True when the comment contains meaning-bearing prose.
     */
    private function isMeaningfulPropertyComment(string $rawText, Property $property): bool
    {
        $text = preg_replace('/^\s*(?:\/\/|#)\s?/', '', $rawText) ?? $rawText;
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        preg_match_all('/[a-z][a-z0-9]*/i', strtolower($text), $matches);
        $words = $matches[0];
        if (count($words) < 2) {
            return false;
        }

        if (array_diff_key(array_fill_keys($words, true), self::PROPERTY_COMMENT_FILLER_WORDS) === []) {
            return false;
        }

        $normalisedComment = $this->normalisedPropertyCommentText($text);
        foreach ($property->props as $propertyProperty) {
            if ($normalisedComment === $this->normalisedPropertyCommentText($propertyProperty->name->toString())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalizes property names and short prose for restatement comparison.
     *
     * @param string $text - Property name or comment prose.
     *
     * @return string - Lowercase alphanumeric-only text.
     */
    private function normalisedPropertyCommentText(string $text): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '';
    }

    /**
     * Builds one declared-property documentation finding.
     *
     * @param string         $propertyName - bare property name without the leading `$`; combined with
     *                                     `$className` to form the `Class::$prop` symbol shown to the reviewer.
     * @param string         $className    - short class name owning the property, for the same symbol.
     * @param int            $line         - 1-based start line of the property statement, so the finding
     *                                     anchors at the declaration the author must annotate, not at the individual prop expression.
     * @param RuleDefinition $definition   - rule metadata source for the finding's id, severity, pillar,
     *                                     tier, and confidence.
     * @param AnalysisUnit   $analysisUnit - parsed unit supplying the display path recorded on the finding.
     *
     * @return Finding - Finding for an undocumented declared property.
     */
    private function declaredPropertyFinding(
        string $propertyName,
        string $className,
        int $line,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): Finding {
        $symbol = sprintf('%s::$%s', $className, $propertyName);

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('Property %s needs a brief intent description above its declaration (one plain-English line; not a restatement of the type).', $symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Add a one-line `/** Description. */` block above the property. This rule wants content, not boilerplate - the description should answer "what does this property hold, what invariant does it maintain."',
            metadata:    [
                'propertyName' => $propertyName,
                'kind' => 'declared',
                'className' => $className,
            ],
        );
    }

}
