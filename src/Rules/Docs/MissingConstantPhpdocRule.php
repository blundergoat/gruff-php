<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Flags a class constant or enum case that carries no local documentation, so the user can see at a glance
 * what each value means and why it exists instead of decoding a bare literal.
 *
 * Runs per file over classes, traits, interfaces, and enums. A meaningful attached line or block comment
 * satisfies a constant. Shipped group categories cover contiguous declarations, while an explicit patterns
 * or regexes family comment covers at most five declared names. Enum cases are reported only when the enum
 * itself is undocumented. Turn on `requirePhpdocForApiConstants`, or list `apiPathPatterns`, to demand real
 * PHPDoc on exported public and protected constants. Advisory, medium confidence.
 */
final readonly class MissingConstantPhpdocRule implements RuleInterface
{
    /** Bounded classification for newly recognised pattern-family comments. */
    private const GROUP_COMMENT_BOUNDED = 'bounded';

    /** Uncapped classification preserving the rule's already-shipped group words. */
    private const GROUP_COMMENT_SHIPPED = 'shipped';

    /** Maximum declared names covered by one bounded pattern-family comment. */
    private const MAX_BOUNDED_GROUP_NAMES = 5;

    /** Newly recognised group words whose inherited coverage is deliberately bounded. */
    private const BOUNDED_GROUP_WORDS = ['patterns' => true, 'regexes' => true];

    /** Existing group words whose contiguous coverage remains uncapped for compatibility. */
    private const SHIPPED_GROUP_WORDS = [
        'fields' => true,
        'keys' => true,
        'modes' => true,
        'options' => true,
        'roles' => true,
        'scopes' => true,
        'sources' => true,
        'states' => true,
        'statuses' => true,
        'tabs' => true,
        'types' => true,
        'values' => true,
    ];

    /**
     * Stable rule identifier for missing constant PHPDoc findings.
     */
    public const ID = 'docs.missing-constant-phpdoc';

    /**
     * Describes the missing-constant PHPDoc rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing constant PHPDoc',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  [
                'requirePhpdocForApiConstants' => false,
                'apiPathPatterns' => [],
            ],
            description: 'Requires constants to explain their purpose with PHPDoc or meaningful local comments; explicit patterns/regexes families cover at most five names while shipped group categories keep contiguous coverage.',
            optionDescriptions: [
                'requirePhpdocForApiConstants' => 'When true, public and protected constants require PHPDoc even when they have useful local comments.',
                'apiPathPatterns' => 'Project-relative glob patterns whose public/protected constants require PHPDoc for exported API documentation.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'Application constants use concise local `//` comments rather than PHPDoc.',
                    'mitigation' => 'Default behaviour accepts meaningful attached local comments, contiguous shipped categories, and the first five names under explicit patterns/regexes family comments; enable `requirePhpdocForApiConstants` only for exported API surfaces.',
                ],
            ],
        );
    }

    /**
     * Reports each class constant and enum case that lacks local documentation.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for undocumented constants.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);
        $findings   = [];

        // Check every class-like declaration in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, ClassLike::class) as $classLike) {
            // Only a named class, trait, interface, or enum can own constants or cases.
            if (!$this->isSupportedClassLike($classLike) || $classLike->name === null) {
                continue;
            }

            array_push(
                $findings,
                ...$this->classConstantFindings($classLike, $classLike->name->toString(), $definition, $analysisUnit, $settings),
                ...$this->enumCaseFindings($classLike, $classLike->name->toString(), $definition, $analysisUnit),
            );
        }

        return $findings;
    }

    /**
     * Reports whether a class-like node can own constants or enum cases.
     *
     * @param ClassLike $classLike - Class-like node to test; only constant- and case-bearing kinds qualify.
     *
     * @return bool - True when the node should be inspected.
     */
    private function isSupportedClassLike(ClassLike $classLike): bool
    {
        // True only for the four node kinds that can carry constants or enum cases worth documenting.
        return $classLike instanceof Class_
            || $classLike instanceof Trait_
            || $classLike instanceof Interface_
            || $classLike instanceof Enum_;
    }

    /**
     * Finds the undocumented class constants in one class-like node.
     *
     * @param ClassLike      $classLike - Node whose direct `const` statements are scanned for missing docs.
     * @param string         $className - Owning class name, used to build the `Class::CONST` symbol per finding.
     * @param RuleDefinition $definition - Shared rule defaults so every finding carries identical severity and tier.
     * @param AnalysisUnit   $analysisUnit - Parsed unit supplying the display path reported with each finding.
     * @param RuleSettings   $settings - Effective settings controlling whether public/protected constants require PHPDoc.
     *
     * @return list<Finding> - One finding per undocumented constant name; multi-name statements yield several.
     */
    private function classConstantFindings(
        ClassLike $classLike,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        RuleSettings $settings,
    ): array {
        $findings   = [];
        $groupState = $this->emptyGroupState();

        // Walk the class body in order so a group comment can carry to the constants beneath it.
        foreach ($classLike->stmts as $statement) {
            // A non-constant statement, or one already carrying PHPDoc, ends any open comment group.
            if (!$statement instanceof ClassConst || $statement->getDocComment() !== null) {
                $groupState = $this->emptyGroupState();
                continue;
            }

            $groupContext = $this->statementGroupContext($statement, $groupState);
            array_push(
                $findings,
                ...$this->classConstantStatementFindings(
                    statement:    $statement,
                    className:    $className,
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    settings:     $settings,
                    groupContext: $groupContext,
                ),
            );
            $groupState = $this->nextGroupState($statement, $groupState, $groupContext);
        }

        return $findings;
    }

    /**
     * Describes how one declaration relates to its attached or inherited group comment.
     *
     * @param ClassConst $statement - Constant declaration being classified.
     * @param array{commentKind: ?string, classification: ?string, endLine: ?int, nameCount: int, visibility: ?int} $groupState - Active preceding group; nullable fields mean no group is open.
     *
     * @return array{attachedCommentKind: ?string, meaningfulLocalKind: ?string, attachedGroupClassification: ?string, groupClassification: ?string, groupCommentKind: ?string, namesBefore: int, inheritsGroup: bool, visibility: int} - Coverage context for each name and the next state transition.
     */
    private function statementGroupContext(ClassConst $statement, array $groupState): array
    {
        $attachedCommentKind         = $this->localCommentKind($statement, false);
        $meaningfulLocalKind         = $this->localCommentKind($statement, true);
        $attachedGroupClassification = $this->groupLocalCommentClassification($statement);

        // Treat implicit and explicit public declarations as the same visibility boundary.
        $statementVisibility = $statement->isPublic()
            ? Modifiers::PUBLIC
            : $statement->flags & Modifiers::VISIBILITY_MASK;

        // Shipped categories retain their existing cross-visibility behavior; a new bounded family does not.
        $visibilityContinuesGroup = $groupState['classification'] === self::GROUP_COMMENT_SHIPPED
            || $groupState['visibility'] === $statementVisibility;

        // Only a live group immediately above this declaration can be inherited.
        $isConsecutiveInGroup = $groupState['classification'] !== null
            && $groupState['commentKind'] !== null
            && $groupState['endLine'] !== null
            && $visibilityContinuesGroup
            && $statement->getStartLine() === $groupState['endLine'] + 1;

        // Any attached comment owns this declaration and prevents stale group inheritance.
        $inheritsGroup = $meaningfulLocalKind === null
            && $attachedCommentKind === null
            && $isConsecutiveInGroup;

        // Prefer a new family comment; otherwise retain only the immediately inherited group.
        $groupClassification = $attachedGroupClassification
            ?? ($inheritsGroup ? $groupState['classification'] : null);

        // A new family uses its attached comment style, while an inherited family keeps the original style.
        $groupCommentKind = $attachedGroupClassification !== null
            ? $attachedCommentKind
            : ($inheritsGroup ? $groupState['commentKind'] : null);

        // A newly attached family starts at zero; inherited coverage resumes after the names already declared.
        $namesBefore = $attachedGroupClassification !== null ? 0 : $groupState['nameCount'];

        return [
            'attachedCommentKind' => $attachedCommentKind,
            'meaningfulLocalKind' => $meaningfulLocalKind,
            'attachedGroupClassification' => $attachedGroupClassification,
            'groupClassification' => $groupClassification,
            'groupCommentKind' => $groupCommentKind,
            'namesBefore' => $namesBefore,
            'inheritsGroup' => $inheritsGroup,
            'visibility' => $statementVisibility,
        ];
    }

    /**
     * Builds findings for each name in one constant declaration using its resolved comment coverage.
     *
     * @param ClassConst     $statement - Declaration whose names may cross the bounded group edge.
     * @param string         $className - Owning class name used in finding symbols.
     * @param RuleDefinition $definition - Rule metadata stamped onto findings.
     * @param AnalysisUnit   $analysisUnit - Parsed unit supplying the display path.
     * @param RuleSettings   $settings - Effective strict-API settings.
     * @param array{attachedCommentKind: ?string, meaningfulLocalKind: ?string, attachedGroupClassification: ?string, groupClassification: ?string, groupCommentKind: ?string, namesBefore: int, inheritsGroup: bool, visibility: int} $groupContext - Attached and inherited coverage; nullable group fields mean no family comment applies.
     *
     * @return list<Finding> - Findings for uncovered or strict-API names; empty when all names are sufficiently documented.
     */
    private function classConstantStatementFindings(
        ClassConst $statement,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        RuleSettings $settings,
        array $groupContext,
    ): array {
        $findings          = [];
        $requiresApiPhpdoc = $this->requiresPhpdocForApiConstants($statement, $analysisUnit->file->displayPath, $settings);

        // One declaration can cross the fifth-name edge, so judge every declared name separately.
        foreach ($statement->consts as $constantOffset => $const) {
            // A null classification means this name cannot borrow group coverage.
            $isCoveredByGroup = $groupContext['groupClassification'] !== null
                && $this->isGroupNameCovered(
                    $groupContext['groupClassification'],
                    $groupContext['namesBefore'] + $constantOffset,
                );

            // A useful single-value comment applies directly only when it did not open a family group.
            $hasDirectUsefulComment = $groupContext['meaningfulLocalKind'] !== null
                && $groupContext['attachedGroupClassification'] === null;
            $hasUsefulComment = $hasDirectUsefulComment || $isCoveredByGroup;

            // A sufficiently documented non-API name produces no finding for the user.
            if ($hasUsefulComment && !$requiresApiPhpdoc) {
                continue;
            }

            $findings[] = $this->classConstantFinding(
                $const->name->toString(),
                $className,
                $statement->getStartLine(),
                $definition,
                $analysisUnit,
                [
                    'kind' => $this->findingCommentKind($groupContext, $isCoveredByGroup),
                    'useful' => $hasUsefulComment,
                    'apiRequired' => $requiresApiPhpdoc,
                    'grouped' => $groupContext['inheritsGroup'] && $isCoveredByGroup,
                ],
            );
        }

        return $findings;
    }

    /**
     * Selects the accepted comment style to expose on one finding.
     *
     * @param array{attachedCommentKind: ?string, meaningfulLocalKind: ?string, attachedGroupClassification: ?string, groupClassification: ?string, groupCommentKind: ?string, namesBefore: int, inheritsGroup: bool, visibility: int} $groupContext - Resolved declaration coverage.
     * @param bool $isCoveredByGroup - Whether this specific name remains inside the group budget.
     *
     * @return string|null - Line/block style for an accepted or low-quality local comment; null beyond the cap or without a comment.
     */
    private function findingCommentKind(array $groupContext, bool $isCoveredByGroup): ?string
    {
        // A bounded attached family comment stops applying once this name crosses its budget.
        if ($groupContext['attachedGroupClassification'] !== null) {
            return $isCoveredByGroup ? $groupContext['attachedCommentKind'] : null;
        }

        return $groupContext['attachedCommentKind']
            ?? ($isCoveredByGroup ? $groupContext['groupCommentKind'] : null);
    }

    /**
     * Advances or closes the active group after one declaration has been judged.
     *
     * @param ClassConst $statement - Declaration whose end line and name count advance an active group.
     * @param array{commentKind: ?string, classification: ?string, endLine: ?int, nameCount: int, visibility: ?int} $groupState - State inherited from the preceding declaration.
     * @param array{attachedCommentKind: ?string, meaningfulLocalKind: ?string, attachedGroupClassification: ?string, groupClassification: ?string, groupCommentKind: ?string, namesBefore: int, inheritsGroup: bool, visibility: int} $groupContext - Current declaration's resolved group relationship.
     *
     * @return array{commentKind: ?string, classification: ?string, endLine: ?int, nameCount: int, visibility: ?int} - State for the next declaration; nullable fields mean the group is closed.
     */
    private function nextGroupState(ClassConst $statement, array $groupState, array $groupContext): array
    {
        // A new qualifying comment restarts coverage and its name budget at this declaration.
        if ($groupContext['attachedGroupClassification'] !== null && $groupContext['attachedCommentKind'] !== null) {
            return [
                'commentKind' => $groupContext['attachedCommentKind'],
                'classification' => $groupContext['attachedGroupClassification'],
                'endLine' => $statement->getEndLine(),
                'nameCount' => count($statement->consts),
                'visibility' => $groupContext['visibility'],
            ];
        }

        // A contiguous declaration consumes every name even after bounded coverage is exhausted.
        if ($groupContext['inheritsGroup']) {
            return [
                'commentKind' => $groupState['commentKind'],
                'classification' => $groupState['classification'],
                'endLine' => $statement->getEndLine(),
                'nameCount' => $groupState['nameCount'] + count($statement->consts),
                'visibility' => $groupContext['visibility'],
            ];
        }

        return $this->emptyGroupState();
    }

    /**
     * Creates the closed state used before a group begins or after a boundary.
     *
     * @return array{commentKind: null, classification: null, endLine: null, nameCount: 0, visibility: null} - Empty state that cannot cover a following declaration.
     */
    private function emptyGroupState(): array
    {
        return [
            'commentKind' => null,
            'classification' => null,
            'endLine' => null,
            'nameCount' => 0,
            'visibility' => null,
        ];
    }

    /**
     * Builds one class-constant documentation finding.
     *
     * @param string         $constantName - Bare constant name; combined with the class to form the reported symbol.
     * @param string         $className - Owning class name for the `Class::CONST` symbol and message text.
     * @param int            $line - 1-based line of the `const` statement the finding points the reviewer at.
     * @param RuleDefinition $definition - Rule defaults supplying the id, severity, tier, pillar, and confidence.
     * @param AnalysisUnit   $analysisUnit - Parsed unit whose display path is recorded on the finding.
     * @param array{kind: ?string, useful: bool, apiRequired: bool, grouped: bool} $comment - Comment classification: the attached/grouped comment kind, whether it is meaningful, whether strict API PHPDoc applies, and whether it was inherited from a short constant group.
     *
     * @return Finding - Finding for an undocumented class constant.
     */
    private function classConstantFinding(
        string $constantName,
        string $className,
        int $line,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        array $comment,
    ): Finding {
        $symbol            = sprintf('%s::%s', $className, $constantName);
        $commentKind       = $comment['kind'];
        $hasUsefulComment  = $comment['useful'];
        $requiresApiPhpdoc = $comment['apiRequired'];

        // An exported constant with only a local comment still needs promotion to PHPDoc.
        if ($requiresApiPhpdoc && $hasUsefulComment) {
            $message     = sprintf('Constant %s has a local comment, but this project requires PHPDoc for exported constants.', $symbol);
            $remediation = sprintf('Promote the local comment above %s into a `/** ... */` block, or narrow `rules.docs.missing-constant-phpdoc.options.apiPathPatterns` / disable `requirePhpdocForApiConstants` if this is not exported API.', $symbol);
        } elseif ($commentKind !== null) {
            // A comment exists but does not explain the constant, so ask for a better one.
            $message     = sprintf('Constant %s has a local comment, but it does not explain the constant\'s purpose.', $symbol);
            $remediation = sprintf('Replace the comment above %s with a concise explanation of why, when, or how the constant is used.', $symbol);
        } else {
            // No nearby comment at all, so ask for one from scratch.
            $message     = sprintf('Constant %s has no nearby comment explaining its purpose.', $symbol);
            $remediation = 'Add an immediately preceding `//` comment or PHPDoc block that explains why, when, or how the constant is used. Avoid restating the constant name or literal value.';
        }

        $metadata = [
            'constantName' => $constantName,
            'kind' => 'class-constant',
            'className' => $className,
            'commentQuality' => $hasUsefulComment ? 'meaningful' : ($commentKind !== null ? 'low-quality' : 'missing'),
        ];

        // Record which comment style was found, when there was one.
        if ($commentKind !== null) {
            $metadata['commentKind'] = $commentKind;
        }

        // Note when the strict exported-API requirement drove the finding.
        if ($requiresApiPhpdoc) {
            $metadata['requiresApiPhpdoc'] = true;
        }

        // Note when the constant only borrowed a shared group comment.
        if ($comment['grouped']) {
            $metadata['groupedLocalComment'] = true;
        }

        // The advisory finding a reviewer diffs: names the constant to document and how (add vs promote a comment).
        return new Finding(
            ruleId:      $definition->id,
            message:     $message,
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: $remediation,
            metadata:    $metadata,
        );
    }

    /**
     * Finds undocumented enum cases when the enum itself is undocumented.
     *
     * @param ClassLike      $classLike - Node inspected; non-enums and documented enums short-circuit to none.
     * @param string         $className - Owning enum name, used to build the `Enum::CASE` symbol per finding.
     * @param RuleDefinition $definition - Shared rule defaults so every finding carries identical severity and tier.
     * @param AnalysisUnit   $analysisUnit - Parsed unit supplying the display path reported with each finding.
     *
     * @return list<Finding> - One finding per undocumented enum case; empty when every case is documented.
     */
    private function enumCaseFindings(
        ClassLike $classLike,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): array {
        if (!$classLike instanceof Enum_ || $classLike->getDocComment() !== null) {
            // A class-level enum docblock already documents the cases, so per-case findings would be noise.
            return [];
        }

        $findings = [];
        // Check each enum case for its own PHPDoc.
        foreach ($classLike->stmts as $statement) {
            // A non-case statement, or a case already documented, needs no finding.
            if (!$statement instanceof EnumCase || $statement->getDocComment() !== null) {
                continue;
            }

            $findings[] = $this->enumCaseFinding(
                caseName:       $statement->name->toString(),
                className:      $className,
                line:           $statement->getStartLine(),
                definition:     $definition,
                analysisUnit:   $analysisUnit,
                commentKind:    $this->localCommentKind($statement, meaningfulOnly: true),
            );
        }

        return $findings;
    }

    /**
     * Builds one enum-case documentation finding.
     *
     * @param string         $caseName - Bare case name; combined with the enum to form the reported symbol.
     * @param string         $className - Owning enum name for the `Enum::CASE` symbol and message text.
     * @param int            $line - 1-based line of the `case` statement the finding points the reviewer at.
     * @param RuleDefinition $definition - Rule defaults supplying the id, severity, tier, pillar, and confidence.
     * @param AnalysisUnit   $analysisUnit - Parsed unit whose display path is recorded on the finding.
     * @param string|null    $commentKind - Attached useful non-doc comment kind; switches the message to "promote".
     *
     * @return Finding - Finding for an undocumented enum case.
     */
    private function enumCaseFinding(
        string $caseName,
        string $className,
        int $line,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        ?string $commentKind,
    ): Finding {
        $symbol = sprintf('%s::%s', $className, $caseName);

        $message = $commentKind !== null
            ? sprintf('Enum case %s has a leading non-PHPDoc comment but no PHPDoc - convert it to `/** ... */` for tooling consumers.', $symbol)
            : sprintf('Enum case %s needs a brief intent description above its declaration (one plain-English line; not a restatement of the case name) and the enum itself is undocumented.', $symbol);

        $remediation = $commentKind !== null
            ? sprintf('Promote the existing comment above %s into a `/** ... */` block, or document the enum at the class level.', $symbol)
            : 'Document either each case with a one-line `/** Description. */` block or add a class-level docblock to the enum. The description should answer "what does this case represent and when is it used".';

        $metadata = [
            'constantName' => $caseName,
            'kind' => 'enum-case',
            'className' => $className,
        ];

        // Record the existing comment style when the case already had one.
        if ($commentKind !== null) {
            $metadata['commentKind'] = $commentKind;
        }

        // The advisory finding a reviewer diffs: it names the case to document and how (add vs promote a comment).
        return new Finding(
            ruleId:      $definition->id,
            message:     $message,
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: $remediation,
            metadata:    $metadata,
        );
    }

    /**
     * Detects an immediately attached non-doc comment.
     *
     * @param Node\Stmt $statement - Const or case statement whose attached leading comments are examined.
     * @param bool      $meaningfulOnly - Whether generic/restating comments should be ignored.
     *
     * @return string|null - `line`/`block` for an attached local comment, or null when absent or not meaningful enough
     */
    private function localCommentKind(Node\Stmt $statement, bool $meaningfulOnly): ?string
    {
        // Look at each comment the parser attached to the statement.
        foreach ($statement->getComments() as $comment) {
            // Only a non-doc comment on the line directly above counts as attached to this constant.
            if ($comment instanceof Doc || $comment->getEndLine() !== $statement->getStartLine() - 1) {
                continue;
            }

            $kind = $this->commentKind($comment);
            // Skip anything that is not a recognised line or block comment.
            if ($kind === null) {
                continue;
            }

            // Accept the comment when any kind is allowed, or when it carries real intent.
            if (!$meaningfulOnly || $this->isMeaningfulCommentText($comment->getText(), $statement)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Classifies an attached meaningful group comment for inheritance and budgeting.
     *
     * @param ClassConst $statement - Constant statement with an attached comment candidate.
     *
     * @return string|null - Shipped or bounded classification; null when no qualifying family comment exists.
     */
    private function groupLocalCommentClassification(ClassConst $statement): ?string
    {
        // Scan the attached comments for a meaningful group-style note.
        foreach ($statement->getComments() as $comment) {
            // Only an immediately preceding non-doc comment qualifies.
            if ($comment instanceof Doc || $comment->getEndLine() !== $statement->getStartLine() - 1) {
                continue;
            }

            // A meaningful comment that names a category can cover this declaration and constants that follow it.
            if ($this->commentKind($comment) !== null
                && $this->isMeaningfulCommentText($comment->getText(), $statement)
            ) {
                $classification = $this->groupCommentClassification($comment->getText());

                // Keep looking when this useful comment describes one value rather than a family.
                if ($classification !== null) {
                    return $classification;
                }
            }
        }

        return null;
    }

    /**
     * Reports whether one zero-based group-name position is covered by its classification.
     *
     * @param string $classification - Shipped or bounded group classification.
     * @param int    $nameOffset - Zero-based name position across the contiguous group.
     *
     * @return bool - True for every shipped-group name or the first five bounded-group names.
     */
    private function isGroupNameCovered(string $classification, int $nameOffset): bool
    {
        return $classification === self::GROUP_COMMENT_SHIPPED
            || $nameOffset < self::MAX_BOUNDED_GROUP_NAMES;
    }

    /**
     * Reports whether strict PHPDoc mode applies to public/protected constants in this path.
     *
     * @param ClassConst   $statement - Constant declaration being inspected.
     * @param string       $displayPath - Repository-relative path being analysed.
     * @param RuleSettings $settings - Effective rule settings.
     *
     * @return bool - True when this exported constant must use PHPDoc rather than a local comment.
     */
    private function requiresPhpdocForApiConstants(ClassConst $statement, string $displayPath, RuleSettings $settings): bool
    {
        // A private constant is never exported API, so the strict requirement never applies.
        if ($statement->isPrivate()) {
            return false;
        }

        // The project can demand PHPDoc on every public or protected constant regardless of path.
        if ($settings->option('requirePhpdocForApiConstants') === true) {
            return true;
        }

        return $this->matchesAnyPathPattern($displayPath, $settings->stringListOption('apiPathPatterns'));
    }

    /**
     * Reports whether a path matches any configured API glob.
     *
     * @param string       $displayPath - Repository-relative path.
     * @param list<string> $patterns - Configured API path globs.
     *
     * @return bool - True when the path belongs to a strict API documentation surface.
     */
    private function matchesAnyPathPattern(string $displayPath, array $patterns): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        // Test the path against each configured API glob.
        foreach ($patterns as $pattern) {
            // A single matching glob marks this file as an exported API surface.
            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinguishes line and block comments from other comment tokens.
     *
     * @param Comment $comment - Already-confirmed non-doc comment whose opening delimiter is classified.
     *
     * @return string|null - `line` for `//`/`#`, `block` for `/* ... *\/`, or null when unknown
     */
    private function commentKind(Comment $comment): ?string
    {
        $text = ltrim($comment->getText());

        // A double-slash or hash opening marks a line comment.
        if (str_starts_with($text, '//') || str_starts_with($text, '#')) {
            return 'line';
        }

        // A slash-star opening marks a block comment.
        if (str_starts_with($text, '/*')) {
            return 'block';
        }

        return null;
    }

    /**
     * Reports whether local comment prose is useful enough to satisfy a private constant.
     *
     * @param string         $rawText - Raw parser comment text, delimiters included.
     * @param Node\Stmt      $statement - Constant statement whose names are used to reject duplicate comments.
     *
     * @return bool - True when the comment has human-facing intent rather than boilerplate.
     */
    private function isMeaningfulCommentText(string $rawText, Node\Stmt $statement): bool
    {
        $text = $this->plainCommentText($rawText);
        // An empty comment says nothing about the constant.
        if ($text === '') {
            return false;
        }

        $words = $this->commentWords($text);
        // A single-word comment rarely explains intent.
        if (count($words) < 2) {
            return false;
        }

        $genericWords = [
            'const' => true,
            'constant' => true,
            'constants' => true,
            'default' => true,
            'defaults' => true,
            'value' => true,
            'values' => true,
            'setting' => true,
            'settings' => true,
            'config' => true,
            'configuration' => true,
        ];

        // A comment built only from filler words like "default value" adds nothing.
        if (count(array_diff_key(array_fill_keys($words, true), $genericWords)) === 0) {
            return false;
        }

        // For a constant, a comment that just restates the name is not real documentation.
        if ($statement instanceof ClassConst) {
            // Compare against each name declared in the statement.
            foreach ($statement->consts as $const) {
                // A comment equal to the constant name only echoes it.
                if ($this->normalisedText($text) === $this->normalisedText($const->name->toString())) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Classifies prose that names a consecutive constant family.
     *
     * @param string $rawText - Raw parser comment text, delimiters included.
     *
     * @return string|null - Shipped or bounded classification; null for a single-value or generic comment.
     */
    private function groupCommentClassification(string $rawText): ?string
    {
        $words = array_fill_keys($this->commentWords($this->plainCommentText($rawText)), true);

        // Existing group vocabulary wins whenever a mixed comment also names a new pattern family.
        if (array_intersect_key($words, self::SHIPPED_GROUP_WORDS) !== []) {
            return self::GROUP_COMMENT_SHIPPED;
        }

        // Pattern-family words receive the newly approved bounded behavior.
        if (array_intersect_key($words, self::BOUNDED_GROUP_WORDS) !== []) {
            return self::GROUP_COMMENT_BOUNDED;
        }

        return null;
    }

    /**
     * Strips comment delimiters and leading block-comment stars.
     *
     * @param string $rawText - Raw comment text.
     *
     * @return string - Plain prose content.
     */
    private function plainCommentText(string $rawText): string
    {
        $text = preg_replace('/^\s*(?:\/\/|#)\s?/m', '', $rawText) ?? $rawText;
        $text = preg_replace('/^\s*\/\*\s?|\s*\*\/\s*$/', '', $text) ?? $text;
        $text = preg_replace('/^\s*\*\s?/m', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Splits prose into lowercase words for generic-comment checks.
     *
     * @param string $text - Plain comment prose.
     *
     * @return list<string> - Lowercase words.
     */
    private function commentWords(string $text): array
    {
        preg_match_all('/[a-z][a-z0-9]*/i', strtolower($text), $matches);

        return $matches[0];
    }

    /**
     * Normalises identifiers and short prose for duplicate-comment comparison.
     *
     * @param string $text - Comment text or constant name.
     *
     * @return string - Lowercase alphanumeric-only text.
     */
    private function normalisedText(string $text): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '';
    }
}
