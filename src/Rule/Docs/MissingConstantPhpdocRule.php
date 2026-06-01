<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
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
        // Advisory/medium-confidence defaults the registry and config layer read to wire this rule up.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing constant PHPDoc',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
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
        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, ClassLike::class) as $classLike) {
            if (!$this->isSupportedClassLike($classLike) || $classLike->name === null) {
                continue;
            }

            array_push(
                $findings,
                ...$this->classConstantFindings($classLike, $classLike->name->toString(), $definition, $analysisUnit),
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
     *
     * @return list<Finding> - Findings for undocumented class constants.
     */
    private function classConstantFindings(
        ClassLike $classLike,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): array {
        $findings = [];

        foreach ($classLike->stmts as $statement) {
            if (!$statement instanceof ClassConst || $statement->getDocComment() !== null) {
                continue;
            }

            $hasLineComment = $this->hasLeadingLineComment($statement);

            foreach ($statement->consts as $const) {
                $findings[] = $this->classConstantFinding(
                    constantName:   $const->name->toString(),
                    className:      $className,
                    line:           $statement->getStartLine(),
                    definition:     $definition,
                    analysisUnit:   $analysisUnit,
                    hasLineComment: $hasLineComment,
                );
            }
        }

        // One finding per undocumented constant name; a multi-name `const A, B;` statement yields several.
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
     * @param bool           $hasLineComment - True when a leading `//` exists; switches the message to "promote".
     *
     * @return Finding - Finding for an undocumented class constant.
     */
    private function classConstantFinding(
        string $constantName,
        string $className,
        int $line,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        bool $hasLineComment,
    ): Finding {
        $symbol = sprintf('%s::%s', $className, $constantName);

        $message = $hasLineComment
            ? sprintf('Constant %s has a leading `//` line comment but no PHPDoc - convert to `/** ... */` for tooling consumers.', $symbol)
            : sprintf('Constant %s needs a brief intent description above its declaration (one plain-English line; not a restatement of the value).', $symbol);

        $remediation = $hasLineComment
            ? sprintf('Promote the existing `//` comment above %s into a `/** ... */` block so docblock-aware tooling can read it.', $symbol)
            : 'Add a one-line `/** Description. */` block. This rule wants content, not boilerplate - if your project policy is "no comments", that policy is about avoiding comments that restate code, not about removing documentation. The description should answer "what is this for, what does it represent at the edge value, what must the caller satisfy."';

        $metadata = [
            'constantName' => $constantName,
            'kind' => 'class-constant',
            'className' => $className,
        ];

        if ($hasLineComment) {
            $metadata['commentKind'] = 'line';
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
     * @return list<Finding> - Findings for undocumented enum cases.
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
                hasLineComment: $this->hasLeadingLineComment($statement),
            );
        }

        // One finding per undocumented case; empty when every case already carries its own docblock.
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
     * @param bool           $hasLineComment - True when a leading `//` exists; switches the message to "promote".
     *
     * @return Finding - Finding for an undocumented enum case.
     */
    private function enumCaseFinding(
        string $caseName,
        string $className,
        int $line,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        bool $hasLineComment,
    ): Finding {
        $symbol = sprintf('%s::%s', $className, $caseName);

        $message = $hasLineComment
            ? sprintf('Enum case %s has a leading `//` line comment but no PHPDoc - convert to `/** ... */` for tooling consumers.', $symbol)
            : sprintf('Enum case %s needs a brief intent description above its declaration (one plain-English line; not a restatement of the case name) and the enum itself is undocumented.', $symbol);

        $remediation = $hasLineComment
            ? sprintf('Promote the existing `//` comment above %s into a `/** ... */` block, or document the enum at the class level.', $symbol)
            : 'Document either each case with a one-line `/** Description. */` block or add a class-level docblock to the enum. The description should answer "what does this case represent and when is it used".';

        $metadata = [
            'constantName' => $caseName,
            'kind' => 'enum-case',
            'className' => $className,
        ];

        if ($hasLineComment) {
            $metadata['commentKind'] = 'line';
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
     * Detect a leading non-doc `//` or `#` line comment attached to the statement.
     *
     * @param Node\Stmt $statement - Const or case statement whose attached leading comments are examined.
     *
     * @return bool - true when the statement already has a leading line comment worth promoting to PHPDoc
     */
    private function hasLeadingLineComment(Node\Stmt $statement): bool
    {
        foreach ($statement->getComments() as $comment) {
            if (!$comment instanceof Doc && $this->isLineComment($comment)) {
                // A leading `//`/`#` already records intent, so the finding steers toward promoting it to a docblock.
                return true;
            }
        }

        // No line comment present, so the constant or case is genuinely undocumented.
        return false;
    }

    /**
     * Distinguish `//` and `#` single-line comment shapes from block comments.
     *
     * @param Comment $comment - Already-confirmed non-doc comment whose opening delimiter is classified.
     *
     * @return bool - true when the comment uses a single-line delimiter rather than a block delimiter
     */
    private function isLineComment(Comment $comment): bool
    {
        $text = ltrim($comment->getText());

        // True for the `//` and `#` line forms; `/* ... */` block comments fall through to false.
        return str_starts_with($text, '//') || str_starts_with($text, '#');
    }
}
