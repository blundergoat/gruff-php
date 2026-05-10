<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;

final readonly class MissingReturnTagRule implements RuleInterface
{
    public const ID = 'docs.missing-return-tag';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Missing @return tag',
            pillar: Pillar::Documentation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $nodes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node */
            if ($node instanceof ClassMethod && !$node->isPublic()) {
                continue;
            }

            $docComment = $node->getDocComment();

            if ($docComment === null) {
                continue;
            }

            $returnType = $node->getReturnType();

            if ($returnType === null) {
                continue;
            }

            if ($returnType instanceof Identifier && $returnType->toString() === 'void') {
                continue;
            }

            if ($this->signatureTypeFullyDescribes($returnType)) {
                continue;
            }

            $docText = $docComment->getText();

            if (str_contains($docText, '@return')) {
                continue;
            }

            if (!str_contains($docText, '@param')) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf('%s has a docblock but no @return tag.', $symbol),
                filePath: $unit->file->displayPath,
                line: $node->getStartLine(),
                severity: $definition->defaultSeverity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                symbol: $symbol,
                remediation: 'Add @return tag documenting the return value.',
            );
        }

        return $findings;
    }

    private function signatureTypeFullyDescribes(?Node $type): bool
    {
        if ($type === null) {
            return false;
        }

        if ($type instanceof Identifier) {
            $name = strtolower($type->toString());
            return $name !== 'array' && $name !== 'iterable';
        }

        if ($type instanceof Name) {
            return true;
        }

        if ($type instanceof NullableType) {
            return $this->signatureTypeFullyDescribes($type->type);
        }

        if ($type instanceof UnionType) {
            foreach ($type->types as $member) {
                if (!$this->signatureTypeFullyDescribes($member)) {
                    return false;
                }
            }

            return true;
        }

        if ($type instanceof IntersectionType) {
            return true;
        }

        return false;
    }
}
