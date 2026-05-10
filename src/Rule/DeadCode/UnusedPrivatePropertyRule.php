<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

final readonly class UnusedPrivatePropertyRule implements RuleInterface
{
    public const ID = 'dead-code.unused-private-property';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Unused private property',
            pillar: Pillar::DeadCode,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $classLikes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof Class_
                || $node instanceof Trait_
                || $node instanceof Enum_;
        });

        $findings = [];

        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike */
            $privateProps = $this->privateProperties($classLike);

            if ($privateProps === []) {
                continue;
            }

            $usage = $this->propertyUsage($finder, $classLike, $privateProps);
            $findings = array_merge(
                $findings,
                $this->findingsForProperties($unit, $definition, $classLike, $privateProps, $usage),
            );
        }

        return $findings;
    }

    /**
     * @param Class_|Trait_|Enum_ $classLike
     * @return array<string, Stmt\Property>
     */
    private function privateProperties(Class_|Trait_|Enum_ $classLike): array
    {
        $privateProps = [];

        foreach ($classLike->stmts as $stmt) {
            if (!$stmt instanceof Stmt\Property || !$stmt->isPrivate()) {
                continue;
            }

            foreach ($stmt->props as $prop) {
                $privateProps[$prop->name->toString()] = $stmt;
            }
        }

        return $privateProps;
    }

    /**
     * @param Class_|Trait_|Enum_ $classLike
     * @param array<string, Stmt\Property> $privateProps
     * @return array{reads: array<string, true>, writes: array<string, true>}
     */
    private function propertyUsage(NodeFinder $finder, Class_|Trait_|Enum_ $classLike, array $privateProps): array
    {
        $reads = [];
        $writes = [];
        $allNodes = $finder->find($classLike->stmts, static fn (): bool => true);
        $ownClassName = $classLike instanceof Class_ ? $classLike->name?->toString() : ($classLike->name?->toString() ?? null);

        foreach ($allNodes as $node) {
            $name = $this->propertyAccessName($node, $ownClassName);

            if ($name === null || !isset($privateProps[$name])) {
                continue;
            }

            $this->recordPropertyUsage($node, $name, $reads, $writes);
        }

        return ['reads' => $reads, 'writes' => $writes];
    }

    /**
     * @param array<string, true> $reads
     * @param array<string, true> $writes
     */
    private function recordPropertyUsage(Node $node, string $name, array &$reads, array &$writes): void
    {
        $parent = $node->getAttribute('parent');

        if ($parent instanceof Expr\Assign && $parent->var === $node) {
            $writes[$name] = true;

            return;
        }

        if ($parent instanceof Expr\AssignOp && $parent->var === $node) {
            $writes[$name] = true;
            $reads[$name] = true;

            return;
        }

        $reads[$name] = true;
    }

    /**
     * @param Class_|Trait_|Enum_ $classLike
     * @param array<string, Stmt\Property> $privateProps
     * @param array{reads: array<string, true>, writes: array<string, true>} $usage
     * @return list<Finding>
     */
    private function findingsForProperties(
        AnalysisUnit $unit,
        RuleDefinition $definition,
        Class_|Trait_|Enum_ $classLike,
        array $privateProps,
        array $usage,
    ): array {
        $findings = [];
        $className = $this->resolveClassName($classLike);

        foreach ($privateProps as $name => $stmt) {
            $isRead = isset($usage['reads'][$name]);
            $isWritten = isset($usage['writes'][$name]) || $stmt->props[0]->default !== null;

            if ($isRead && $isWritten) {
                continue;
            }

            $symbol = sprintf('%s::$%s', $className, $name);
            $findings[] = new Finding(
                ruleId: $definition->id,
                message: $this->propertyMessage($symbol, $isRead, $isWritten),
                filePath: $unit->file->displayPath,
                line: $stmt->getStartLine(),
                severity: $definition->defaultSeverity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                symbol: $symbol,
                remediation: 'Remove the unused property or add the missing read/write.',
                metadata: ['read' => $isRead, 'written' => $isWritten],
            );
        }

        return $findings;
    }

    private function propertyMessage(string $symbol, bool $isRead, bool $isWritten): string
    {
        if (!$isRead && !$isWritten) {
            return sprintf('Private property %s is never used.', $symbol);
        }

        if (!$isRead) {
            return sprintf('Private property %s is written but never read.', $symbol);
        }

        return sprintf('Private property %s is read but never explicitly written.', $symbol);
    }

    /**
     * @param Class_|Trait_|Enum_ $node
     */
    private function resolveClassName(Node $node): string
    {
        if ($node instanceof Class_) {
            return $node->name?->toString() ?? 'class@anonymous';
        }

        return $node->name?->toString() ?? sprintf('unknown@%d', $node->getStartLine());
    }

    private function propertyAccessName(Node $node, ?string $ownClassName): ?string
    {
        if ($node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier
        ) {
            return $node->name->toString();
        }

        if ($node instanceof Expr\StaticPropertyFetch
            && $node->name instanceof Node\VarLikeIdentifier
            && $this->refersToOwnClass($node->class, $ownClassName)
        ) {
            return $node->name->toString();
        }

        return null;
    }

    private function refersToOwnClass(Node $class, ?string $ownClassName): bool
    {
        if (!$class instanceof Node\Name) {
            return false;
        }

        $reference = strtolower($class->toString());

        if ($reference === 'self' || $reference === 'static') {
            return true;
        }

        return $ownClassName !== null && strtolower($class->getLast()) === strtolower($ownClassName);
    }
}
