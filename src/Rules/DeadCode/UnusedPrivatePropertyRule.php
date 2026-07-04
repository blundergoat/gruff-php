<?php

declare(strict_types=1);

namespace GruffPhp\Rules\DeadCode;

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
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Flags a private property that its class never meaningfully uses - never read, never written, or
 * untouched entirely - so the user can drop dead state or fix a missing access.
 *
 * Runs per file: for each class, trait, or enum it collects the private properties (including
 * constructor-promoted ones and declared defaults), then walks every `$this->`/`self::`/`static::`
 * access to record reads and writes. A property that is not both read and written is reported, with a
 * message tuned to which half is missing.
 */
final readonly class UnusedPrivatePropertyRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused private property findings.
     */
    public const ID = 'dead-code.unused-private-property';

    /**
     * Describes the unused-private-property rule for the registry and reports.
     *
     * @return RuleDefinition - the rule's stable id, name, pillar, tier, and the default severity/confidence the registry applies unless overridden.
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
     * Reports each private property that is unread, unwritten, or fully unused, class by class.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per private property that is unread, unwritten, or fully unused across every class-like in the unit; empty
     *                       when all are live.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];

        // Check each class, trait, or enum in the file.
        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike Finder predicate restricts results to class-like declarations. */
            $privateProps = $this->privateProperties($classLike);

            // Nothing to check when the class declares no private properties.
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
     * Collects a class-like's private properties - declared and constructor-promoted - with their write state.
     *
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration whose private properties are collected.
     *
     * @return array<string, array{line: int, writtenByDeclaration: bool}> - private property names mapped to their declaration line and whether a
     *                       default or promotion already writes them; empty when the class-like has none.
     */
    private function privateProperties(Class_|Trait_|Enum_ $classLike): array
    {
        $privateProps = [];

        // First pass: the explicitly declared private properties.
        foreach ($classLike->stmts as $stmt) {
            // Only private property declarations count.
            if (!$stmt instanceof Stmt\Property || !$stmt->isPrivate()) {
                continue;
            }

            // One declaration can define several properties; key each by name.
            foreach ($stmt->props as $prop) {
                $privateProps[$prop->name->toString()] = [
                    'line'                 => $prop->getStartLine(),
                    // A declared default already counts as a write.
                    'writtenByDeclaration' => $prop->default !== null,
                ];
            }
        }

        // Second pass: constructor-promoted private properties.
        foreach ($classLike->stmts as $stmt) {
            // Only the constructor can promote properties.
            if (!$stmt instanceof Stmt\ClassMethod || strtolower($stmt->name->toString()) !== '__construct') {
                continue;
            }

            // Check each promoted parameter.
            foreach ($stmt->params as $param) {
                // Skip non-private promotions.
                if (($param->flags & Modifiers::PRIVATE) === 0) {
                    continue;
                }

                // Skip a malformed parameter with no plain name.
                if (!$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                    continue;
                }

                $privateProps[$param->var->name] = [
                    'line'                 => $param->getStartLine(),
                    'writtenByDeclaration' => true,
                ];
            }
        }

        return $privateProps;
    }

    /**
     * Records which tracked private properties are read and which are written across the class body.
     *
     * @param NodeFinder                                                  $nodeFinder - Walks the body for accesses.
     * @param Class_|Trait_|Enum_                                         $classLike - Owner of the accesses.
     * @param array<string, array{line: int, writtenByDeclaration: bool}> $privateProps - Names to track; rest ignored.
     *
     * @return array{reads: array<string, true>, writes: array<string, true>} - two name-keyed sets flagging which tracked properties were read and
     *                      which were written; a name absent from both was never accessed.
     */
    private function propertyUsage(NodeFinder $nodeFinder, Class_|Trait_|Enum_ $classLike, array $privateProps): array
    {
        $reads        = [];
        $writes       = [];
        $allNodes     = $nodeFinder->find($classLike->stmts, static fn(): bool => true);
        $ownClassName = $classLike instanceof Class_ ? $classLike->name?->toString() : ($classLike->name?->toString() ?? null);

        // Walk every node, resolving any self-referential property access.
        foreach ($allNodes as $node) {
            $name = $this->propertyAccessName($node, $ownClassName);

            // Ignore anything that is not an access to one of our tracked properties.
            if ($name === null || !isset($privateProps[$name])) {
                continue;
            }

            $this->recordPropertyUsage($node, $name, $reads, $writes);
        }

        return ['reads' => $reads, 'writes' => $writes];
    }

    /**
     * Marks one property access as a read, a write, or both, by looking at its parent node.
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
     * Builds one finding per private property that is not both read and written.
     *
     * @param AnalysisUnit                                                   $analysisUnit - Path stamped on findings.
     * @param RuleDefinition                                                 $definition - Source of id and severity.
     * @param Class_|Trait_|Enum_                                            $classLike - Owner; name prefixes ids.
     * @param array<string, array{line: int, writtenByDeclaration: bool}>    $privateProps - Defaulted means written.
     * @param array{reads: array<string, true>, writes: array<string, true>} $usage - Reads/writes per name.
     *
     * @return list<Finding> - one finding per property that failed the read-and-written test; empty when every private property is live.
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

        // Report each property that fails the read-and-written test.
        foreach ($privateProps as $name => $property) {
            $isRead    = isset($usage['reads'][$name]);
            $isWritten = isset($usage['writes'][$name]) || $property['writtenByDeclaration'];

            // A property both read and written is live, so skip it.
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
     * Phrases the finding message for a property's usage state: never used, write-only, or read-only.
     *
     * @param string $symbol - Fully qualified property symbol to name in the message.
     * @param bool   $isRead - Whether any read of the property was seen.
     * @param bool   $isWritten - Whether the property is ever written: true if an assignment was seen OR the
     *                          declaration carries a default, so true with zero observed writes means default-only.
     *
     * @return string - the finding message phrased for the property's usage state: never used, written-but-never-read, or
     *                read-but-never-explicitly-written.
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
     * Returns a display name for the class-like, using a stable placeholder when it is unnamed.
     *
     * @param Class_|Trait_|Enum_ $node - Class-like declaration whose display symbol is needed for finding text.
     *
     * @return string - the declared class/trait/enum name, or a stable placeholder (class@anonymous, or unknown@line for a malformed tree) when no
     *                name node exists.
     */
    private function resolveClassName(Node $node): string
    {
        if ($node instanceof Class_) {
            // Anonymous classes have no name node, so fall back to a stable placeholder.
            return $node->name?->toString() ?? 'class@anonymous';
        }

        // Traits and enums are always named; the line-tagged fallback only guards against malformed input.
        return $node->name?->toString() ?? sprintf('unknown@%d', $node->getStartLine());
    }

    /**
     * Reads the property name from a `$this->` or own-class static access, or null for anything else.
     *
     * @param Node        $node - Candidate access node; only $this-> and own-class static fetches qualify.
     * @param string|null $ownClassName - Enclosing class name, or null inside a trait/anonymous scope.
     *
     * @return string|null - the accessed property name for $this-> or own-class static fetches; null means the node is not a self-referential
     *                     property access and carries no usage signal.
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
     * Reports whether a static-access target points back at the enclosing class-like scope.
     *
     * @param Node        $class - Class reference from a static fetch; non-Name targets never match.
     * @param string|null $ownClassName - Enclosing class name to compare against, or null when there is none.
     *
     * @return bool - true when the static target is self::/static:: or matches the enclosing class name; false for dynamic targets and for
     *              trait/anonymous scopes that have no name.
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
