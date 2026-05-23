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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for missing file-level documentation.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if ($analysisUnit->statements === []) {
            return [];
        }

        $topLevel = $this->topLevelStatements($analysisUnit);

        if ($topLevel === []) {
            return [];
        }

        if ($this->isSingleDocumentedClassLikeFile($topLevel)) {
            return [];
        }

        if ($this->hasFirstStatementDoc($topLevel[0])) {
            return [];
        }

        return $this->buildFinding($analysisUnit, $topLevel[0]);
    }

    /**
     * List top-level statements that count toward file documentation.
     *
     * @return list<Node\Stmt>
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

        return $effective;
    }

    /**
     * @param list<Node\Stmt> $statements
     *
     * @return bool True when the file has one documented class-like declaration.
     */
    private function isSingleDocumentedClassLikeFile(array $statements): bool
    {
        $classLikes = array_values(array_filter(
            $statements,
            static fn (Node\Stmt $stmt): bool => $stmt instanceof ClassLike,
        ));

        if (count($classLikes) !== 1) {
            return false;
        }

        return $classLikes[0]->getDocComment() !== null;
    }

    /**
     * Check whether the first effective statement carries a docblock comment.
     *
     * @return bool True when a docblock is attached to the statement.
     */
    private function hasFirstStatementDoc(Node\Stmt $statement): bool
    {
        foreach ($statement->getComments() as $comment) {
            if ($comment instanceof Doc) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build finding for the documentation rule.
     *
     * @return list<Finding>
     */
    private function buildFinding(AnalysisUnit $analysisUnit, Node\Stmt $first): array
    {
        $definition = $this->definition();

        return [
            new Finding(
                ruleId:      $definition->id,
                message:     sprintf('File %s has no file-level docblock.', $analysisUnit->file->displayPath),
                filePath:    $analysisUnit->file->displayPath,
                line:        1,
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $analysisUnit->file->displayPath,
                remediation: 'Add a file-level docblock describing the file\'s purpose, or document the file\'s single declared type with a class-level docblock.',
                metadata:    [
                    'firstStatementKind' => $this->statementKind($first),
                ],
            ),
        ];
    }

    /**
     * Return a compact statement kind for finding metadata.
     *
     * @return string Lowercase parser statement kind.
     */
    private function statementKind(Node\Stmt $node): string
    {
        $class = $node::class;
        $short = substr($class, (int) strrpos($class, '\\') + 1);

        return strtolower(rtrim($short, '_'));
    }
}
