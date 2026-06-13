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
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Detects class constants and enum cases that lack local PHPDoc.
 */
final readonly class MissingConstantPhpdocRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing constant PHPDoc findings.
     */
    public const ID = 'docs.missing-constant-phpdoc';

    /**
     * Describe the missing constant PHPDoc rule.
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
            optionDescriptions: [
                'requirePhpdocForApiConstants' => 'When true, public and protected constants require PHPDoc even when they have useful local comments.',
                'apiPathPatterns' => 'Project-relative glob patterns whose public/protected constants require PHPDoc for exported API documentation.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'Application constants use concise local `//` comments rather than PHPDoc.',
                    'mitigation' => 'Default behaviour accepts meaningful attached local comments; enable `requirePhpdocForApiConstants` only for exported API surfaces.',
                ],
            ],
        );
    }

    /**
     * Find class constants and enum cases without PHPDoc.
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

        foreach (NodeIndex::nodesOf($analysisUnit, ClassLike::class) as $classLike) {
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
     * Check whether a class-like node can own constants or enum cases.
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
     * Find undocumented class constants in one class-like node.
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
        $findings            = [];
        $groupCommentKind    = null;
        $groupCommentEndLine = null;

        foreach ($classLike->stmts as $statement) {
            if (!$statement instanceof ClassConst || $statement->getDocComment() !== null) {
                $groupCommentKind    = null;
                $groupCommentEndLine = null;
                continue;
            }

            $attachedCommentKind  = $this->localCommentKind($statement, false);
            $meaningfulLocalKind  = $this->localCommentKind($statement, true);
            $groupedCommentKind   = null;
            $isConsecutiveInGroup = $groupCommentKind !== null
                && $groupCommentEndLine !== null
                && $statement->getStartLine() === $groupCommentEndLine + 1;

            if ($meaningfulLocalKind === null && $attachedCommentKind === null && $isConsecutiveInGroup) {
                $meaningfulLocalKind = $groupCommentKind;
                $groupedCommentKind  = $groupCommentKind;
            }

            $requiresApiPhpdoc = $this->requiresPhpdocForApiConstants($statement, $analysisUnit->file->displayPath, $settings);
            $hasUsefulComment  = $meaningfulLocalKind !== null;

            if ($hasUsefulComment && !$requiresApiPhpdoc) {
                if ($attachedCommentKind !== null && $this->hasGroupLocalComment($statement)) {
                    $groupCommentKind    = $attachedCommentKind;
                    $groupCommentEndLine = $statement->getEndLine();
                } elseif ($groupedCommentKind !== null) {
                    $groupCommentEndLine = $statement->getEndLine();
                } else {
                    $groupCommentKind    = null;
                    $groupCommentEndLine = null;
                }

                continue;
            }

            foreach ($statement->consts as $const) {
                $findings[] = $this->classConstantFinding(
                    constantName:       $const->name->toString(),
                    className:          $className,
                    line:               $statement->getStartLine(),
                    definition:         $definition,
                    analysisUnit:       $analysisUnit,
                    commentKind:        $attachedCommentKind ?? $groupedCommentKind,
                    hasUsefulComment:   $hasUsefulComment,
                    requiresApiPhpdoc:  $requiresApiPhpdoc,
                    groupedLocalComment: $groupedCommentKind !== null,
                );
            }

            if ($hasUsefulComment && $attachedCommentKind !== null && $this->hasGroupLocalComment($statement)) {
                $groupCommentKind    = $attachedCommentKind;
                $groupCommentEndLine = $statement->getEndLine();
            } elseif ($groupedCommentKind !== null) {
                $groupCommentEndLine = $statement->getEndLine();
            } else {
                $groupCommentKind    = null;
                $groupCommentEndLine = null;
            }
        }

        return $findings;
    }

    /**
     * Build one class-constant PHPDoc finding.
     *
     * @param string         $constantName - Bare constant name; combined with the class to form the reported symbol.
     * @param string         $className - Owning class name for the `Class::CONST` symbol and message text.
     * @param int            $line - 1-based line of the `const` statement the finding points the reviewer at.
     * @param RuleDefinition $definition - Rule defaults supplying the id, severity, tier, pillar, and confidence.
     * @param AnalysisUnit   $analysisUnit - Parsed unit whose display path is recorded on the finding.
     * @param string|null    $commentKind - Attached non-doc comment kind; switches the message to "improve/promote".
     * @param bool           $hasUsefulComment - Whether a meaningful local comment exists but still fails strict PHPDoc policy.
     * @param bool           $requiresApiPhpdoc - Whether strict API PHPDoc mode applied to this constant.
     * @param bool           $groupedLocalComment - Whether the useful comment was inherited from a short constant group.
     *
     * @return Finding - Finding for an undocumented class constant.
     */
    private function classConstantFinding(
        string $constantName,
        string $className,
        int $line,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        ?string $commentKind,
        bool $hasUsefulComment,
        bool $requiresApiPhpdoc,
        bool $groupedLocalComment,
    ): Finding {
        $symbol = sprintf('%s::%s', $className, $constantName);

        if ($requiresApiPhpdoc && $hasUsefulComment) {
            $message     = sprintf('Constant %s has a local comment, but this project requires PHPDoc for exported constants.', $symbol);
            $remediation = sprintf('Promote the local comment above %s into a `/** ... */` block, or narrow `rules.docs.missing-constant-phpdoc.options.apiPathPatterns` / disable `requirePhpdocForApiConstants` if this is not exported API.', $symbol);
        } elseif ($commentKind !== null) {
            $message     = sprintf('Constant %s has a local comment, but it does not explain the constant\'s purpose.', $symbol);
            $remediation = sprintf('Replace the comment above %s with a concise explanation of why, when, or how the constant is used.', $symbol);
        } else {
            $message     = sprintf('Constant %s has no nearby comment explaining its purpose.', $symbol);
            $remediation = 'Add an immediately preceding `//` comment or PHPDoc block that explains why, when, or how the constant is used. Avoid restating the constant name or literal value.';
        }

        $metadata = [
            'constantName' => $constantName,
            'kind' => 'class-constant',
            'className' => $className,
            'commentQuality' => $hasUsefulComment ? 'meaningful' : ($commentKind !== null ? 'low-quality' : 'missing'),
        ];

        if ($commentKind !== null) {
            $metadata['commentKind'] = $commentKind;
        }

        if ($requiresApiPhpdoc) {
            $metadata['requiresApiPhpdoc'] = true;
        }

        if ($groupedLocalComment) {
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
     * Find undocumented enum cases when the enum itself is undocumented.
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
        foreach ($classLike->stmts as $statement) {
            if (!$statement instanceof EnumCase || $statement->getDocComment() !== null) {
                continue;
            }

            $findings[] = $this->enumCaseFinding(
                caseName:       $statement->name->toString(),
                className:      $className,
                line:           $statement->getStartLine(),
                definition:     $definition,
                analysisUnit:   $analysisUnit,
                commentKind:    $this->localMeaningfulComment($statement),
            );
        }

        return $findings;
    }

    /**
     * Build one enum-case PHPDoc finding.
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
            ? sprintf('Enum case %s has a leading `//` line comment but no PHPDoc - convert to `/** ... */` for tooling consumers.', $symbol)
            : sprintf('Enum case %s needs a brief intent description above its declaration (one plain-English line; not a restatement of the case name) and the enum itself is undocumented.', $symbol);

        $remediation = $commentKind !== null
            ? sprintf('Promote the existing `//` comment above %s into a `/** ... */` block, or document the enum at the class level.', $symbol)
            : 'Document either each case with a one-line `/** Description. */` block or add a class-level docblock to the enum. The description should answer "what does this case represent and when is it used".';

        $metadata = [
            'constantName' => $caseName,
            'kind' => 'enum-case',
            'className' => $className,
        ];

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
     * Detect an immediately attached meaningful non-doc comment.
     *
     * @param Node\Stmt $statement - Const or case statement whose attached leading comments are examined.
     *
     * @return string|null - comment kind when the statement has useful local documentation, or null when absent/useless
     */
    private function localMeaningfulComment(Node\Stmt $statement): ?string
    {
        return $this->localCommentKind($statement, meaningfulOnly: true);
    }

    /**
     * Detect an immediately attached non-doc comment.
     *
     * @param Node\Stmt $statement - Const or case statement whose attached leading comments are examined.
     * @param bool      $meaningfulOnly - Whether generic/restating comments should be ignored.
     *
     * @return string|null - `line`/`block` for an attached local comment, or null when absent or not meaningful enough
     */
    private function localCommentKind(Node\Stmt $statement, bool $meaningfulOnly): ?string
    {
        foreach ($statement->getComments() as $comment) {
            if ($comment instanceof Doc || $comment->getEndLine() !== $statement->getStartLine() - 1) {
                continue;
            }

            $kind = $this->commentKind($comment);
            if ($kind === null) {
                continue;
            }

            if (!$meaningfulOnly || $this->isMeaningfulCommentText($comment->getText(), $statement)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Check whether a meaningful local comment is phrased as a short constant-group comment.
     *
     * @param ClassConst $statement - Constant statement with an attached comment candidate.
     *
     * @return bool - True when the comment can cover immediately consecutive constants in the same group.
     */
    private function hasGroupLocalComment(ClassConst $statement): bool
    {
        foreach ($statement->getComments() as $comment) {
            if ($comment instanceof Doc || $comment->getEndLine() !== $statement->getStartLine() - 1) {
                continue;
            }

            if ($this->commentKind($comment) !== null
                && $this->isMeaningfulCommentText($comment->getText(), $statement)
                && $this->isGroupCommentText($comment->getText())
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether strict PHPDoc mode applies to public/protected constants in this path.
     *
     * @param ClassConst   $statement - Constant declaration being inspected.
     * @param string       $displayPath - Repository-relative path being analysed.
     * @param RuleSettings $settings - Effective rule settings.
     *
     * @return bool - True when this exported constant must use PHPDoc rather than a local comment.
     */
    private function requiresPhpdocForApiConstants(ClassConst $statement, string $displayPath, RuleSettings $settings): bool
    {
        if ($statement->isPrivate()) {
            return false;
        }

        if ($settings->option('requirePhpdocForApiConstants') === true) {
            return true;
        }

        return $this->matchesAnyPathPattern($displayPath, $settings->stringListOption('apiPathPatterns'));
    }

    /**
     * Check whether a path matches any configured API glob.
     *
     * @param string       $displayPath - Repository-relative path.
     * @param list<string> $patterns - Configured API path globs.
     *
     * @return bool - True when the path belongs to a strict API documentation surface.
     */
    private function matchesAnyPathPattern(string $displayPath, array $patterns): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinguish line and block comments from other comment tokens.
     *
     * @param Comment $comment - Already-confirmed non-doc comment whose opening delimiter is classified.
     *
     * @return string|null - `line` for `//`/`#`, `block` for `/* ... *\/`, or null when unknown
     */
    private function commentKind(Comment $comment): ?string
    {
        $text = ltrim($comment->getText());

        if (str_starts_with($text, '//') || str_starts_with($text, '#')) {
            return 'line';
        }

        if (str_starts_with($text, '/*')) {
            return 'block';
        }

        return null;
    }

    /**
     * Judge whether local comment prose is useful enough to satisfy a private constant.
     *
     * @param string         $rawText - Raw parser comment text, delimiters included.
     * @param Node\Stmt      $statement - Constant statement whose names are used to reject duplicate comments.
     *
     * @return bool - True when the comment has human-facing intent rather than boilerplate.
     */
    private function isMeaningfulCommentText(string $rawText, Node\Stmt $statement): bool
    {
        $text = $this->plainCommentText($rawText);
        if ($text === '') {
            return false;
        }

        $words = $this->commentWords($text);
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

        if (count(array_diff_key(array_fill_keys($words, true), $genericWords)) === 0) {
            return false;
        }

        if ($statement instanceof ClassConst) {
            foreach ($statement->consts as $const) {
                if ($this->normalisedText($text) === $this->normalisedText($const->name->toString())) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Detect prose that is likely intended to cover a short consecutive constant group.
     *
     * @param string $rawText - Raw parser comment text, delimiters included.
     *
     * @return bool - True when the comment names a group/category rather than a single value.
     */
    private function isGroupCommentText(string $rawText): bool
    {
        $words      = array_fill_keys($this->commentWords($this->plainCommentText($rawText)), true);
        $groupWords = [
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

        return count(array_intersect_key($words, $groupWords)) > 0;
    }

    /**
     * Strip comment delimiters and leading block-comment stars.
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
     * Split prose into lowercase words for generic-comment checks.
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
     * Normalise identifiers and short prose for duplicate-comment comparison.
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
