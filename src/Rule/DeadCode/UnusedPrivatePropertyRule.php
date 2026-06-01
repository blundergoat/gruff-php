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
     * @return RuleDefinition - the rule's stable id, name, pillar, tier, and the default severity/confidence the registry applies unless overridden
     */
    public function definition(): RuleDefinition
    {
        // High-confidence warning: an unread/unwritten private member is dead and safe to remove.
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
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per private property that is unread, unwritten, or fully unused across every class-like in the unit; empty
     *                       when all are live
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
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration whose private properties are collected.
     *
     * @return array<string, array{line: int, writtenByDeclaration: bool}> - private property names mapped to their declaration line and whether a
     *                       default or promotion already writes them; empty when the class-like has none
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
                    'line'                 => $prop->getStartLine(),
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
                    'line'                 => $param->getStartLine(),
                    'writtenByDeclaration' => true,
                ];
            }
        }

        // Plain declarations plus promoted constructor params, keyed by name; later keys win on collision.
        return $privateProps;
    }

    /**
     * Track reads and writes for collected private properties.
     *
     * @param NodeFinder                                                  $nodeFinder - Walks the body for accesses.
     * @param Class_|Trait_|Enum_                                         $classLike - Owner of the accesses.
     * @param array<string, array{line: int, writtenByDeclaration: bool}> $privateProps - Names to track; rest ignored.
     *
     * @return array{reads: array<string, true>, writes: array<string, true>} - two name-keyed sets flagging which tracked properties were read and
     *                      which were written; a name absent from both was never accessed
     */
    private function propertyUsage(NodeFinder $nodeFinder, Class_|Trait_|Enum_ $classLike, array $privateProps): array
    {
        $reads        = [];
        $writes       = [];
        $allNodes     = $nodeFinder->find($classLike->stmts, static fn(): bool => true);
        $ownClassName = $classLike instanceof Class_ ? $classLike->name?->toString() : ($classLike->name?->toString() ?? null);

        foreach ($allNodes as $node) {
            $name = $this->propertyAccessName($node, $ownClassName);

            if ($name === null || !isset($privateProps[$name])) {
                continue;
            }

            $this->recordPropertyUsage($node, $name, $reads, $writes);
        }

        // Two name-keyed sets the caller intersects: a property absent from both is unused.
        return ['reads' => $reads, 'writes' => $writes];
    }

    /**
     * Record read/write usage for a private property access node.
     *
     * @param Node                $node - Access node already matched to $name; its parent decides read vs write.
     * @param string              $name - Property whose usage flag to set.
     * @param array<string, true> $reads - Accumulator, keyed by name; receives true when this access reads.
     * @param array<string, true> $writes - Accumulator, keyed by name; receives true when this access assigns.
     *
     * @return void
     */
    private function recordPropertyUsage(Node $node, string $name, array &$reads, array &$writes): void
    {
        $parent = $node->getAttribute('parent');

        if ($parent instanceof Expr\Assign && $parent->var === $node) {
            $writes[$name] = true;

            // A plain assignment target is written only; stop before it also counts as a read.
            return;
        }

        if ($parent instanceof Expr\AssignOp && $parent->var === $node) {
            $writes[$name] = true;
            $reads[$name]  = true;

            // Compound assignment ($x += ...) both reads and writes; done once both are recorded.
            return;
        }

        $reads[$name] = true;
    }

    /**
     * Build findings for properties in the dead-code rule.
     *
     * @param AnalysisUnit                                                   $analysisUnit - Path stamped on findings.
     * @param RuleDefinition                                                 $definition - Source of id and severity.
     * @param Class_|Trait_|Enum_                                            $classLike - Owner; name prefixes ids.
     * @param array<string, array{line: int, writtenByDeclaration: bool}>    $privateProps - Defaulted means written.
     * @param array{reads: array<string, true>, writes: array<string, true>} $usage - Reads/writes per name.
     *
     * @return list<Finding> - one finding per property that failed the read-and-written test; empty when every private property is live
     */
    private function findingsForProperties(
        AnalysisUnit        $analysisUnit,
        RuleDefinition      $definition,
        Class_|Trait_|Enum_ $classLike,
        array               $privateProps,
        array               $usage,
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

        // One finding per property that failed the read-and-written test; empty when all are live.
        return $findings;
    }

    /**
     * Build the finding message for a private property usage state.
     *
     * @param string $symbol - Fully qualified property symbol to name in the message.
     * @param bool   $isRead - Whether any read of the property was seen.
     * @param bool   $isWritten - Whether the property is ever written: true if an assignment was seen OR the
     *                          declaration carries a default, so true with zero observed writes means default-only.
     *
     * @return string - the finding message phrased for the property's usage state: never used, written-but-never-read, or
     *                read-but-never-explicitly-written
     */
    private function propertyMessage(string $symbol, bool $isRead, bool $isWritten): string
    {
        if (!$isRead && !$isWritten) {
            // Neither read nor written: the strongest claim, so report it first.
            return sprintf('Private property %s is never used.', $symbol);
        }

        if (!$isRead) {
            // Written somewhere but never consumed; the value computed into it is dead.
            return sprintf('Private property %s is written but never read.', $symbol);
        }

        // Remaining case ($isRead, !$isWritten): read somewhere, yet has no declaration default and is
        // never assigned, so every read observes the implicit null/uninitialised value, not a stored one.
        return sprintf('Private property %s is read but never explicitly written.', $symbol);
    }

    /**
     * Resolve a display name for a class-like node.
     *
     * @param Class_|Trait_|Enum_ $node - Class-like declaration whose display symbol is needed for finding text.
     *
     * @return string - the declared class/trait/enum name, or a stable placeholder (class@anonymous, or unknown@line for a malformed tree) when no
     *                name node exists
     */
    private function resolveClassName(Node $node): string
    {
        if ($node instanceof Class_) {
            // Anonymous classes carry no name node, so stand in with a stable placeholder.
            return $node->name?->toString() ?? 'class@anonymous';
        }

        // Traits and enums are always named; the line-tagged fallback only guards a malformed tree.
        return $node->name?->toString() ?? sprintf('unknown@%d', $node->getStartLine());
    }

    /**
     * Extract the private property name from `$this` or own-class static access.
     *
     * @param Node        $node - Candidate access node; only $this-> and own-class static fetches qualify.
     * @param string|null $ownClassName - Enclosing class name, or null inside a trait/anonymous scope.
     *
     * @return string|null - the accessed property name for $this-> or own-class static fetches; null means the node is not a self-referential
     *                     property access and carries no usage signal
     */
    private function propertyAccessName(Node $node, ?string $ownClassName): ?string
    {
        if ($node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier
        ) {
            // Plain $this->name access; the literal property name is what usage tracking keys on.
            return $node->name->toString();
        }

        if ($node instanceof Expr\StaticPropertyFetch
            && $node->name instanceof Node\VarLikeIdentifier
            && $this->refersToOwnClass($node->class, $ownClassName)
        ) {
            // self::/static::/OwnClass:: access to the same property; treat it like a $this read or write.
            return $node->name->toString();
        }

        // Not a self-referential property access, so it tells us nothing about this class's members.
        return null;
    }

    /**
     * Check whether a static access target refers to the current class-like scope.
     *
     * @param Node        $class - Class reference from a static fetch; non-Name targets never match.
     * @param string|null $ownClassName - Enclosing class name to compare against, or null when there is none.
     *
     * @return bool - true when the static target is self::/static:: or matches the enclosing class name; false for dynamic targets and for
     *              trait/anonymous scopes that have no name
     */
    private function refersToOwnClass(Node $class, ?string $ownClassName): bool
    {
        if (!$class instanceof Node\Name) {
            // Dynamic targets (variables, expressions) can't be resolved statically, so treat as foreign.
            return false;
        }

        $reference = strtolower($class->toString());

        if ($reference === 'self' || $reference === 'static') {
            // self:: and static:: always point at the enclosing class, regardless of its name.
            return true;
        }

        // Otherwise match the unqualified class name; null scope (trait/anonymous) can never match.
        return $ownClassName !== null && strtolower($class->getLast()) === strtolower($ownClassName);
    }
}
