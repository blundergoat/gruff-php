<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

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
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Detects private properties that are never meaningfully read or written.
 */
final readonly class UnusedPrivatePropertyRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused private property findings.
     */
    public const ID = 'dead-code.unused-private-property';

    /**
     * Describe the unused private property rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unused private property',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find private properties that are never read, never written, or unused entirely.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for unused private properties.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];

        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike Finder predicate restricts results to class-like declarations. */
            $privateProps = $this->privateProperties($classLike);

            if ($privateProps === []) {
                continue;
            }

            $usage    = $this->propertyUsage($nodeFinder, $classLike, $privateProps);
            $findings = array_merge(
                $findings,
                $this->findingsForProperties(
                    analysisUnit: $analysisUnit,
                    definition:   $definition,
                    classLike:    $classLike,
                    privateProps: $privateProps,
                    usage:        $usage,
                ),
            );
        }

        return $findings;
    }

    /**
     * Collect private properties declared on a class-like node.
     *
     * @param Class_|Trait_|Enum_ $classLike
     * @return array<string, array{line: int, writtenByDeclaration: bool}>
     */
    private function privateProperties(Class_|Trait_|Enum_ $classLike): array
    {
        $privateProps = [];

        foreach ($classLike->stmts as $stmt) {
            if (!$stmt instanceof Stmt\Property || !$stmt->isPrivate()) {
                continue;
            }

            foreach ($stmt->props as $prop) {
                $privateProps[$prop->name->toString()] = [
                    'line' => $prop->getStartLine(),
                    'writtenByDeclaration' => $prop->default !== null,
                ];
            }
        }

        foreach ($classLike->stmts as $stmt) {
            if (!$stmt instanceof Stmt\ClassMethod || strtolower($stmt->name->toString()) !== '__construct') {
                continue;
            }

            foreach ($stmt->params as $param) {
                if (($param->flags & Modifiers::PRIVATE) === 0) {
                    continue;
                }

                if (!$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                    continue;
                }

                $privateProps[$param->var->name] = [
                    'line' => $param->getStartLine(),
                    'writtenByDeclaration' => true,
                ];
            }
        }

        return $privateProps;
    }

    /**
     * Track reads and writes for collected private properties.
     *
     * @param Class_|Trait_|Enum_                                         $classLike
     * @param array<string, array{line: int, writtenByDeclaration: bool}> $privateProps
     * @return array{reads: array<string, true>, writes: array<string, true>}
     */
    private function propertyUsage(NodeFinder $nodeFinder, Class_|Trait_|Enum_ $classLike, array $privateProps): array
    {
        $reads        = [];
        $writes       = [];
        $allNodes     = $nodeFinder->find($classLike->stmts, static fn (): bool => true);
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
     * Record read/write usage for a private property access node.
     *
     * @param array<string, true> $reads
     * @param array<string, true> $writes
     *
     * @return void
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
            $reads[$name]  = true;

            return;
        }

        $reads[$name] = true;
    }

    /**
     * Build findings for properties in the dead-code rule.
     *
     * @param Class_|Trait_|Enum_                                            $classLike
     * @param array<string, array{line: int, writtenByDeclaration: bool}>    $privateProps
     * @param array{reads: array<string, true>, writes: array<string, true>} $usage
     * @return list<Finding>
     */
    private function findingsForProperties(
        AnalysisUnit $analysisUnit,
        RuleDefinition $definition,
        Class_|Trait_|Enum_ $classLike,
        array $privateProps,
        array $usage,
    ): array {
        $findings  = [];
        $className = $this->resolveClassName($classLike);

        foreach ($privateProps as $name => $property) {
            $isRead    = isset($usage['reads'][$name]);
            $isWritten = isset($usage['writes'][$name]) || $property['writtenByDeclaration'];

            if ($isRead && $isWritten) {
                continue;
            }

            $symbol     = sprintf('%s::$%s', $className, $name);
            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     $this->propertyMessage($symbol, $isRead, $isWritten),
                filePath:    $analysisUnit->file->displayPath,
                line:        $property['line'],
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Remove the unused property or add the missing read/write.',
                metadata:    ['read' => $isRead, 'written' => $isWritten],
            );
        }

        return $findings;
    }

    /**
     * Build the finding message for a private property usage state.
     *
     * @return string Human-readable finding message.
     */
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
     * Resolve a display name for a class-like node.
     *
     * @param Class_|Trait_|Enum_ $node
     *
     * @return string Class-like display name.
     */
    private function resolveClassName(Node $node): string
    {
        if ($node instanceof Class_) {
            return $node->name?->toString() ?? 'class@anonymous';
        }

        return $node->name?->toString() ?? sprintf('unknown@%d', $node->getStartLine());
    }

    /**
     * Extract the private property name from `$this` or own-class static access.
     *
     * @return string|null Property name, or null when the node is not supported.
     */
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

    /**
     * Check whether a static access target refers to the current class-like scope.
     *
     * @return bool True when the target is self/static or the own class name.
     */
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
