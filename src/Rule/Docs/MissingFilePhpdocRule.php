<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;

/**
 * Detects files that lack file-level or single-type structural documentation.
 */
final readonly class MissingFilePhpdocRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing file PHPDoc findings.
     */
    public const ID = 'docs.missing-file-phpdoc';

    /**
     * Describe the missing file PHPDoc rule.
     *
     * @return RuleDefinition - this rule's identity, pillar, tier, and default severity/confidence used by the registry
     */
    public function definition(): RuleDefinition
    {
        // Advisory, medium-confidence metadata for the documentation pillar.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing file PHPDoc',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find files that lack a file-level docblock or a documented sole class-like declaration.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - at most one finding when the file lacks file-level docs; empty when the file is documented or has nothing to anchor to
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if ($analysisUnit->statements === []) {
            // An empty file carries no declaration to demand documentation of.
            return [];
        }

        $topLevel = $this->topLevelStatements($analysisUnit);

        if ($topLevel === []) {
            // Nothing survives namespace unwrapping; no top-level statement to anchor a finding to.
            return [];
        }

        if ($this->isSingleDocumentedClassLikeFile($topLevel)) {
            // A lone documented class-like already orients the reader; the file docblock is redundant.
            return [];
        }

        if ($this->hasFirstStatementDoc($topLevel[0])) {
            // A docblock on the first statement satisfies the file-level documentation requirement.
            return [];
        }

        // No file-level or sole-type documentation found; emit the missing-doc finding.
        return $this->buildFinding($analysisUnit, $topLevel[0]);
    }

    /**
     * List top-level statements that count toward file documentation.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose namespaced statements are flattened.
     *
     * @return list<Node\Stmt> - statements hoisted out of any namespace wrapper, in source order; empty when the file declares none
     */
    private function topLevelStatements(AnalysisUnit $analysisUnit): array
    {
        $effective = [];

        foreach ($analysisUnit->statements as $statement) {
            if ($statement instanceof Namespace_) {
                foreach ($statement->stmts as $inner) {
                    $effective[] = $inner;
                }
                continue;
            }

            $effective[] = $statement;
        }

        // Statements hoisted out of any namespace wrapper, in source order.
        return $effective;
    }

    /**
     * @param list<Node\Stmt> $statements - Effective top-level statements after namespace wrappers are flattened.
     *
     * @return bool - true only when the file declares exactly one class-like and it carries its own docblock; false otherwise
     */
    private function isSingleDocumentedClassLikeFile(array $statements): bool
    {
        $classLikes = array_values(array_filter(
                                       $statements,
                                       static fn(Node\Stmt $stmt): bool => $stmt instanceof ClassLike,
                                   ));

        if (count($classLikes) !== 1) {
            // Zero or multiple types means file-level docs are still required.
            return false;
        }

        // True only when the sole class-like declaration carries its own docblock.
        return $classLikes[0]->getDocComment() !== null;
    }

    /**
     * Check whether the first effective statement carries a docblock comment.
     *
     * @param Node\Stmt $statement - First top-level statement whose attached comments are scanned.
     *
     * @return bool - true when a structured docblock is attached; false when only line or block comments are present
     */
    private function hasFirstStatementDoc(Node\Stmt $statement): bool
    {
        foreach ($statement->getComments() as $comment) {
            if ($comment instanceof Doc) {
                // A structured docblock counts as file-level documentation.
                return true;
            }
        }

        // Only line or block comments were attached; no docblock present.
        return false;
    }

    /**
     * Build finding for the documentation rule.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying the display path reported in the finding.
     * @param Node\Stmt    $first - First top-level statement, used to record the offending statement kind.
     *
     * @return list<Finding> - single advisory finding anchored at line 1 flagging the absent file-level documentation
     */
    private function buildFinding(AnalysisUnit $analysisUnit, Node\Stmt $first): array
    {
        $definition = $this->definition();

        // Single finding anchored at line 1 flagging the absent file-level documentation.
        return [
            new Finding(
                ruleId:      $definition->id,
                message:     sprintf('File %s needs a brief intent description at the top (one plain-English line; not a restatement of the filename or namespace).', $analysisUnit->file->displayPath),
                filePath:    $analysisUnit->file->displayPath,
                line:        1,
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $analysisUnit->file->displayPath,
                remediation: 'Add a one-line `/** Description. */` block at the top of the file or on the single declared type. This rule wants content, not boilerplate - if your project policy is "no comments", that policy is about avoiding comments that restate code, not about removing documentation.',
                metadata:    [
                                 'firstStatementKind' => $this->statementKind($first),
                             ],
            ),
        ];
    }

    /**
     * Return a compact statement kind for finding metadata.
     *
     * @param Node\Stmt $node - Statement whose class name is reduced to a short kind label.
     *
     * @return string - short parser node name, lower-cased with the trailing underscore stripped (e.g. "class", "function"), for finding metadata
     */
    private function statementKind(Node\Stmt $node): string
    {
        $class = $node::class;
        $short = substr($class, (int)strrpos($class, '\\') + 1);

        // Short class name, lower-cased with the parser's trailing underscore dropped (e.g. "class").
        return strtolower(rtrim($short, '_'));
    }
}
