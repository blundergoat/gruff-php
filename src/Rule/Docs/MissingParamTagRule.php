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
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;

final readonly class MissingParamTagRule implements RuleInterface
{
    public const ID = 'docs.missing-param-tag';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Missing @param tag',
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

            if ($docComment === null || $node->params === []) {
                continue;
            }

            $docText = $docComment->getText();
            $documentedParams = $this->extractParamNames($docText);

            if ($documentedParams === []) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            foreach ($node->params as $param) {
                if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                $paramName = $param->var->name;

                if (in_array($paramName, $documentedParams, true)) {
                    continue;
                }

                if ($this->signatureTypeFullyDescribes($param->type)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId: $definition->id,
                    message: sprintf('Parameter $%s in %s has no @param tag.', $paramName, $symbol),
                    filePath: $unit->file->displayPath,
                    line: $param->getStartLine(),
                    severity: $definition->defaultSeverity,
                    pillar: $definition->pillar,
                    tier: $definition->tier,
                    confidence: $definition->confidence,
                    symbol: $symbol,
                    remediation: sprintf('Add @param tag for $%s.', $paramName),
                    metadata: ['parameter' => $paramName],
                );
            }
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

    /**
     * @return list<string>
     */
    public static function extractParamNames(string $docText): array
    {
        $result = [];
        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            if (preg_match('/@param\s+.+?\s+\$(\w+)\b/', $line, $matches) !== 1) {
                continue;
            }

            $result[] = $matches[1];
        }

        return $result;
    }
}
