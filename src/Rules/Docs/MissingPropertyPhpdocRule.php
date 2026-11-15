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
use GruffPhp\Support\DeclarationLine;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;

/**
 * Flags undocumented properties in named classes, traits, interfaces, and enums.
 * An own-line docblock immediately above the first property covers its run unless a later property also has one.
 * Blank lines and non-property statements end a run.
 *
 * Meaningful attached // or # comments count only with acceptLineComments enabled.
 * Advisory severity and medium confidence reflect team-dependent documentation needs.
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
        // Advisory with Medium confidence because teams differ on whether trivial properties need documentation.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Missing property PHPDoc',
            pillar:             Pillar::Documentation,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::Medium,
            defaultOptions:     ['acceptLineComments' => false],
            description:        'Requires declared properties to explain their purpose with PHPDoc, where a docblock above the first property of an unbroken run covers the run unless another property in it has its own; an opt-in toggle also accepts meaningful attached line comments.',
            optionDescriptions: [
                'acceptLineComments' => 'When true, a physically attached // or # comment with meaning beyond the property name satisfies the rule.',
            ],
            falsePositiveShapes: [
                [
                    'shape'      => 'A property documented by an attached // or # line comment rather than a docblock.',
                    'mitigation' => 'The default configuration accepts only docblocks; set options.acceptLineComments to true, or convert the comment to `/** */`.',
                ],
                [
                    'shape'      => 'With acceptLineComments enabled, a short comment whose words only restate the property name.',
                    'mitigation' => 'Such a comment is treated as filler, so state what the property holds or the invariant it maintains.',
                ],
            ],
        );
    }

    /**
     * Reports undocumented properties from the named class-like declarations this rule supports.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Supplies acceptLineComments, which admits attached line comments that pass the text screen.
     *
     * @return list<Finding> - Findings for undocumented properties.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition               = $this->definition();
        $findings                 = [];
        $shouldAcceptLineComments = $ruleContext->settingsFor($definition)->option('acceptLineComments') === true;


        foreach (NodeIndex::nodesOf($analysisUnit, ClassLike::class) as $classLike) {
            // Anonymous classes are excluded because findings use a named owner in their property symbols.
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
     * Reports whether a class-like node belongs to a supported declaration kind.
     *
     * @param ClassLike $classLike - Candidate node; the caller checks its name separately.
     *
     * @return bool - True for classes, traits, interfaces, or enums, including anonymous classes.
     */
    private function isSupportedClassLike(ClassLike $classLike): bool
    {


        return $classLike instanceof Class_
            || $classLike instanceof Trait_
            || $classLike instanceof Interface_
            || $classLike instanceof Enum_;
    }

    /**
     * Reports properties lacking individual documentation or coverage from a shared run docblock.
     *
     * @param ClassLike $classLike - Class-like declaration whose property statements are checked.
     * @param string $className - Resolved short owner name used in Class::$prop finding symbols.
     * @param RuleDefinition $definition - Rule metadata copied into each finding.
     * @param AnalysisUnit $analysisUnit - Source for run boundaries and attachment checks, plus the finding path.
     * @param bool $shouldAcceptLineComments - Whether attached line comments that pass the text screen satisfy the rule.
     *
     * @return list<Finding> - One finding per property name lacking accepted individual or shared documentation.
     */
    private function declaredPropertyFindings(
        ClassLike $classLike,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        bool $shouldAcceptLineComments,
    ): array {
        $findings = [];

        // Check each unbroken run of property declarations; a blank line or any other statement ends a run.
        foreach (PhysicalCommentAttachment::unbrokenRuns($classLike->stmts, Property::class, $analysisUnit->source) as $propertyRun) {
            $isRunCovered = $this->isRunCoveredByDocblock($propertyRun, $analysisUnit->source);
            foreach ($propertyRun as $position => $property) {
                // PHPDoc covers its declaration, a run-level docblock covers later members, and screened line comments
                // count only under the opt-in toggle.
                if (
                    $property->getDocComment() !== null
                    || ($position > 0 && $isRunCovered)
                    || ($shouldAcceptLineComments && $this->hasMeaningfulAttachedLineComment($property, $analysisUnit->source))
                ) {
                    continue;
                }

                // One declaration can name several properties, so report each.
                foreach ($property->props as $propertyProperty) {
                    $findings[] = $this->declaredPropertyFinding(
                        propertyName: $propertyProperty->name->toString(),
                        className:    $className,
                        line:         DeclarationLine::of($property),
                        definition:   $definition,
                        analysisUnit: $analysisUnit,
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Reports whether a docblock above a run's first property covers the whole run: it does unless a later
     * property in the run has a docblock of its own, which marks per-property documentation.
     *
     * @param non-empty-list<Property> $propertyRun - Unbroken run of property declarations, in source order.
     * @param string                   $source - Whole-file source used to reject detached and trailing docblocks.
     *
     * @return bool - True when the first property's docblock documents every property in the run.
     */
    private function isRunCoveredByDocblock(array $propertyRun, string $source): bool
    {
        // A later property with its own docblock keeps every docblock in the run to its own property.
        foreach (array_slice($propertyRun, 1) as $member) {
            if (PhysicalCommentAttachment::hasOwnComment($member, $source, true)) {
                return false;
            }
        }

        return PhysicalCommentAttachment::hasOwnComment($propertyRun[0], $source, true);
    }

    /**
     * Reports whether an attached own-line comment passes the text screen for more than a property-name restatement.
     *
     * @param Property $property - Property statement whose leading comments are inspected.
     * @param string   $source   - Whole-file source used to reject detached and trailing comments.
     *
     * @return bool - True when an attached // or # comment passes the rule's text screen.
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
     * Screens line-comment text for filler and restatements of declared property names.
     *
     * @param string $rawText - Raw parser comment text including its delimiter.
     * @param Property $property - Property declaration whose names are checked for restatement.
     *
     * @return bool - True when the word-count, non-filler, and normalized-name checks all accept the text.
     */
    private function isMeaningfulPropertyComment(string $rawText, Property $property): bool
    {
        // Strip a leading // or # marker and at most one following whitespace character before screening the prose.
        $text = preg_replace('/^\s*(?:\/\/|#)\s?/', '', $rawText) ?? $rawText;
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $words = $this->propertyCommentWords($text);
        if (count($words) < 2) {
            return false;
        }

        if (array_diff_key(array_fill_keys($words, true), self::PROPERTY_COMMENT_FILLER_WORDS) === []) {
            return false;
        }

        $normalisedComment        = $this->normalisedPropertyCommentText($text);
        $normalisedCommentMeaning = $this->normalisedPropertyCommentMeaning($words);
        foreach ($property->props as $propertyProperty) {
            $propertyName          = $propertyProperty->name->toString();
            $normalisedProperty    = $this->normalisedPropertyCommentText($propertyName);
            $propertyNameWords     = $this->propertyCommentWords($propertyName);
            $normalisedNameMeaning = $this->normalisedPropertyCommentMeaning($propertyNameWords);

            if (
                $normalisedComment === $normalisedProperty
                || $normalisedCommentMeaning === $normalisedNameMeaning
            ) {
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
        // Dropping every non-alphanumeric character makes userId, user_id and "user id" compare equal.
        return preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '';
    }

    /**
     * Splits prose or an identifier into comparable lowercase words.
     *
     * @param string $text - Comment prose or property identifier.
     *
     * @return list<string> - Lowercase alphanumeric words, including words split at camel-case boundaries.
     */
    private function propertyCommentWords(string $text): array
    {
        // Insert a space at each lower-to-upper case boundary, then take letter-led words, so userId reads as "user id".
        $separated = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $text) ?? $text;
        preg_match_all('/[a-z][a-z0-9]*/i', strtolower($separated), $matches);

        return $matches[0];
    }

    /**
     * Builds a comparison key after removing generic property labels and repeated words.
     *
     * @param list<string> $words - Lowercase comment or identifier words.
     *
     * @return string - Unique non-filler words concatenated in first-occurrence order; empty when none remain.
     */
    private function normalisedPropertyCommentMeaning(array $words): string
    {
        $meaningWords = array_diff_key(array_fill_keys($words, true), self::PROPERTY_COMMENT_FILLER_WORDS);

        return implode('', array_keys($meaningWords));
    }

    /**
     * Builds one declared-property documentation finding.
     *
     * @param string $propertyName - Bare property name combined with $className to form the Class::$prop symbol.
     * @param string $className - Short owner name used in the finding symbol.
     * @param int $line - One-based declaration line shared by all names in the property statement.
     * @param RuleDefinition $definition - Source of the finding's rule ID, severity, pillar, tier, and confidence.
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying the finding's display path.
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
