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

final readonly class MissingFilePhpdocRule implements RuleInterface
{
    public const ID = 'docs.missing-file-phpdoc';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Missing file PHPDoc',
            pillar: Pillar::Documentation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        if ($unit->statements === []) {
            return [];
        }

        $topLevel = $this->topLevelStatements($unit);

        if ($topLevel === []) {
            return [];
        }

        if ($this->isSingleDocumentedClassLikeFile($topLevel)) {
            return [];
        }

        if ($this->firstStatementHasDoc($topLevel[0])) {
            return [];
        }

        return $this->buildFinding($unit, $topLevel[0]);
    }

    /**
     * @return list<Node\Stmt>
     */
    private function topLevelStatements(AnalysisUnit $unit): array
    {
        $effective = [];

        foreach ($unit->statements as $statement) {
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

    private function firstStatementHasDoc(Node\Stmt $statement): bool
    {
        foreach ($statement->getComments() as $comment) {
            if ($comment instanceof Doc) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Finding>
     */
    private function buildFinding(AnalysisUnit $unit, Node\Stmt $first): array
    {
        $definition = $this->definition();

        return [
            new Finding(
                ruleId: $definition->id,
                message: sprintf('File %s has no file-level docblock.', $unit->file->displayPath),
                filePath: $unit->file->displayPath,
                line: 1,
                severity: $definition->defaultSeverity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                symbol: $unit->file->displayPath,
                remediation: 'Add a file-level docblock describing the file\'s purpose, or document the file\'s single declared type with a class-level docblock.',
                metadata: [
                    'firstStatementKind' => $this->statementKind($first),
                ],
            ),
        ];
    }

    private function statementKind(Node\Stmt $node): string
    {
        $class = $node::class;
        $short = substr($class, (int) strrpos($class, '\\') + 1);

        return strtolower(rtrim($short, '_'));
    }
}
