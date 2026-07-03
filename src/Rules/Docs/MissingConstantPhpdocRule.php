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
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, ClassLike::class) as $classLike) {
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
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
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: add each item that can appear in findings list.
        foreach ($classLike->stmts as $statement) {
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if (!$statement instanceof ClassConst || $statement->getDocComment() !== null) {
                $groupCommentKind    = null;
                $groupCommentEndLine = null;
                continue;
            }

            $attachedCommentKind  = $this->localCommentKind($statement, false);
            $meaningfulLocalKind  = $this->localCommentKind($statement, true);
            $groupedCommentKind   = null;
            // User view: missing data becomes the expected findings list state.
            $isConsecutiveInGroup = $groupCommentKind !== null
                // User view: missing data becomes the expected findings list state.
                && $groupCommentEndLine !== null
                && $statement->getStartLine() === $groupCommentEndLine + 1;

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($meaningfulLocalKind === null && $attachedCommentKind === null && $isConsecutiveInGroup) {
                $meaningfulLocalKind = $groupCommentKind;
                $groupedCommentKind  = $groupCommentKind;
            }

            $requiresApiPhpdoc = $this->requiresPhpdocForApiConstants($statement, $analysisUnit->file->displayPath, $settings);
            // User view: missing data becomes the expected findings list state.
            $hasUsefulComment  = $meaningfulLocalKind !== null;

            // User view: choose the findings list branch for this case.
            if (!$hasUsefulComment || $requiresApiPhpdoc) {
                // User view: add each item that can appear in findings list.
                foreach ($statement->consts as $const) {
                    $findings[] = $this->classConstantFinding(
                        $const->name->toString(),
                        $className,
                        $statement->getStartLine(),
                        $definition,
                        $analysisUnit,
                        [
                            // User view: missing data becomes a safe findings list default.
                            'kind'        => $attachedCommentKind ?? $groupedCommentKind,
                            'useful'      => $hasUsefulComment,
                            'apiRequired' => $requiresApiPhpdoc,
                            // User view: missing data becomes the expected findings list state.
                            'grouped'     => $groupedCommentKind !== null,
                        ],
                    );
                }
            }

            // Carry the group-comment state forward once; the emit and skip paths shared this logic.
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($hasUsefulComment && $attachedCommentKind !== null && $this->hasGroupLocalComment($statement)) {
                $groupCommentKind    = $attachedCommentKind;
                $groupCommentEndLine = $statement->getEndLine();
            }
            // User view: missing data becomes the expected findings list state.
            // User view: choose the next findings list branch for this case.
            elseif ($groupedCommentKind !== null) {
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
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: choose the findings list branch for this case.
        if ($requiresApiPhpdoc && $hasUsefulComment) {
            $message     = sprintf('Constant %s has a local comment, but this project requires PHPDoc for exported constants.', $symbol);
            $remediation = sprintf('Promote the local comment above %s into a `/** ... */` block, or narrow `rules.docs.missing-constant-phpdoc.options.apiPathPatterns` / disable `requirePhpdocForApiConstants` if this is not exported API.', $symbol);
        }
        // User view: missing data becomes the expected findings list state.
        // User view: choose the next findings list branch for this case.
        elseif ($commentKind !== null) {
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
            // User view: missing data becomes the expected findings list state.
            'commentQuality' => $hasUsefulComment ? 'meaningful' : ($commentKind !== null ? 'low-quality' : 'missing'),
        ];

        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($commentKind !== null) {
            $metadata['commentKind'] = $commentKind;
        }

        // User view: choose the findings list branch for this case.
        if ($requiresApiPhpdoc) {
            $metadata['requiresApiPhpdoc'] = true;
        }

        // User view: choose the findings list branch for this case.
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
     * Find undocumented enum cases when the enum itself is undocumented.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if (!$classLike instanceof Enum_ || $classLike->getDocComment() !== null) {
            // A class-level enum docblock already documents the cases, so per-case findings would be noise.
            return [];
        }

        $findings = [];
        // User view: add each item that can appear in findings list.
        foreach ($classLike->stmts as $statement) {
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
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
     * Build one enum-case PHPDoc finding.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: missing data becomes the expected findings list state.
        $message = $commentKind !== null
            ? sprintf('Enum case %s has a leading non-PHPDoc comment but no PHPDoc - convert it to `/** ... */` for tooling consumers.', $symbol)
            : sprintf('Enum case %s needs a brief intent description above its declaration (one plain-English line; not a restatement of the case name) and the enum itself is undocumented.', $symbol);

        // User view: missing data becomes the expected findings list state.
        $remediation = $commentKind !== null
            ? sprintf('Promote the existing comment above %s into a `/** ... */` block, or document the enum at the class level.', $symbol)
            : 'Document either each case with a one-line `/** Description. */` block or add a class-level docblock to the enum. The description should answer "what does this case represent and when is it used".';

        $metadata = [
            'constantName' => $caseName,
            'kind' => 'enum-case',
            'className' => $className,
        ];

        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
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
     * Detect an immediately attached non-doc comment.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node\Stmt $statement - Const or case statement whose attached leading comments are examined.
     * @param bool      $meaningfulOnly - Whether generic/restating comments should be ignored.
     *
     * @return string|null - `line`/`block` for an attached local comment, or null when absent or not meaningful enough
     */
    private function localCommentKind(Node\Stmt $statement, bool $meaningfulOnly): ?string
    {
        // User view: add each item that can appear in findings list.
        foreach ($statement->getComments() as $comment) {
            // User view: choose the findings list branch for this case.
            if ($comment instanceof Doc || $comment->getEndLine() !== $statement->getStartLine() - 1) {
                continue;
            }

            $kind = $this->commentKind($comment);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($kind === null) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (!$meaningfulOnly || $this->isMeaningfulCommentText($comment->getText(), $statement)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Check whether a meaningful local comment is phrased as a short constant-group comment.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassConst $statement - Constant statement with an attached comment candidate.
     *
     * @return bool - True when the comment can cover immediately consecutive constants in the same group.
     */
    private function hasGroupLocalComment(ClassConst $statement): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ($statement->getComments() as $comment) {
            // User view: choose the findings list branch for this case.
            if ($comment instanceof Doc || $comment->getEndLine() !== $statement->getStartLine() - 1) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassConst   $statement - Constant declaration being inspected.
     * @param string       $displayPath - Repository-relative path being analysed.
     * @param RuleSettings $settings - Effective rule settings.
     *
     * @return bool - True when this exported constant must use PHPDoc rather than a local comment.
     */
    private function requiresPhpdocForApiConstants(ClassConst $statement, string $displayPath, RuleSettings $settings): bool
    {
        // User view: choose the findings list branch for this case.
        if ($statement->isPrivate()) {
            return false;
        }

        // User view: choose the findings list branch for this case.
        if ($settings->option('requirePhpdocForApiConstants') === true) {
            return true;
        }

        return $this->matchesAnyPathPattern($displayPath, $settings->stringListOption('apiPathPatterns'));
    }

    /**
     * Check whether a path matches any configured API glob.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string       $displayPath - Repository-relative path.
     * @param list<string> $patterns - Configured API path globs.
     *
     * @return bool - True when the path belongs to a strict API documentation surface.
     */
    private function matchesAnyPathPattern(string $displayPath, array $patterns): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);

        // User view: add each item that can appear in findings list.
        foreach ($patterns as $pattern) {
            // User view: choose the findings list branch for this case.
            if (fnmatch($pattern, $normalizedPath, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinguish line and block comments from other comment tokens.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Comment $comment - Already-confirmed non-doc comment whose opening delimiter is classified.
     *
     * @return string|null - `line` for `//`/`#`, `block` for `/* ... *\/`, or null when unknown
     */
    private function commentKind(Comment $comment): ?string
    {
        $text = ltrim($comment->getText());

        // User view: choose the findings list branch for this case.
        if (str_starts_with($text, '//') || str_starts_with($text, '#')) {
            return 'line';
        }

        // User view: choose the findings list branch for this case.
        if (str_starts_with($text, '/*')) {
            return 'block';
        }

        return null;
    }

    /**
     * Judge whether local comment prose is useful enough to satisfy a private constant.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string         $rawText - Raw parser comment text, delimiters included.
     * @param Node\Stmt      $statement - Constant statement whose names are used to reject duplicate comments.
     *
     * @return bool - True when the comment has human-facing intent rather than boilerplate.
     */
    private function isMeaningfulCommentText(string $rawText, Node\Stmt $statement): bool
    {
        $text = $this->plainCommentText($rawText);
        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($text === '') {
            return false;
        }

        $words = $this->commentWords($text);
        // User view: choose the findings list branch for this case.
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

        // User view: choose the findings list branch for this case.
        if (count(array_diff_key(array_fill_keys($words, true), $genericWords)) === 0) {
            return false;
        }

        // User view: choose the findings list branch for this case.
        if ($statement instanceof ClassConst) {
            // User view: add each item that can appear in findings list.
            foreach ($statement->consts as $const) {
                // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $rawText - Raw comment text.
     *
     * @return string - Plain prose content.
     */
    private function plainCommentText(string $rawText): string
    {
        // User view: missing data becomes a safe findings list default.
        $text = preg_replace('/^\s*(?:\/\/|#)\s?/m', '', $rawText) ?? $rawText;
        // User view: missing data becomes a safe findings list default.
        $text = preg_replace('/^\s*\/\*\s?|\s*\*\/\s*$/', '', $text) ?? $text;
        // User view: missing data becomes a safe findings list default.
        $text = preg_replace('/^\s*\*\s?/m', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Split prose into lowercase words for generic-comment checks.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $text - Comment text or constant name.
     *
     * @return string - Lowercase alphanumeric-only text.
     */
    private function normalisedText(string $text): string
    {
        // User view: missing data becomes a safe findings list default.
        return preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '';
    }
}
