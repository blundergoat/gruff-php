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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Detects private class-like constants that are never read within their scope.
 */
final readonly class UnusedPrivateConstantRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused private constant findings.
     */
    public const ID = 'dead-code.unused-private-constant';

    /**
     * Describe the unused private constant rule.
     *
     * @return RuleDefinition - id, name, pillar, tier, and the default Warning severity callers apply to each finding
     */
    public function definition(): RuleDefinition
    {
        // High-confidence warning: a private constant no in-scope static access reaches is dead and safe to delete.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unused private constant',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find private constants that are not referenced inside their class-like scope.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per private constant unreferenced in its class-like; empty when none are dead
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];

        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike NodeIndex query is constrained to class-like classes. */
            $privateConstants = $this->privateConstants($classLike);

            if ($privateConstants === []) {
                continue;
            }

            $usage = $this->constantUsage($nodeFinder, $classLike);

            if ($usage['hasDynamicReference']) {
                continue;
            }

            $findings = array_merge(
                $findings,
                $this->findingsForUnusedConstants(
                    analysisUnit:      $analysisUnit,
                    definition:        $definition,
                    classLike:         $classLike,
                    privateConstants:  $privateConstants,
                    referencedNames:   $usage['referencedNames'],
                ),
            );
        }

        // Hand back every unused-private-constant finding gathered across the class-likes in this unit.
        return $findings;
    }

    /**
     * Collect private constants declared on a class-like node.
     *
     * @param Class_|Trait_|Enum_ $classLike
     *
     * @return array<string, Node\Const_> - candidate private declarations keyed by constant name; empty when the class-like declares none
     */
    private function privateConstants(Class_|Trait_|Enum_ $classLike): array
    {
        $privateConstants = [];

        foreach ($classLike->stmts as $stmt) {
            if (!$stmt instanceof Stmt\ClassConst || !$stmt->isPrivate()) {
                continue;
            }

            foreach ($stmt->consts as $constant) {
                $privateConstants[$constant->name->toString()] = $constant;
            }
        }

        // Hand back private class constants keyed by name; enum cases are a different node and never included.
        return $privateConstants;
    }

    /**
     * Collect private-constant reads made inside a class-like node.
     *
     * @param NodeFinder          $nodeFinder Walks the class-like body for constant fetches.
     * @param Class_|Trait_|Enum_ $classLike  Owner whose private constants are being checked.
     *
     * @return array{referencedNames: array<string, true>, hasDynamicReference: bool} - literal names read in scope plus whether an unresolved
     *                                                                                 dynamic local fetch prevents high-confidence findings
     */
    private function constantUsage(NodeFinder $nodeFinder, Class_|Trait_|Enum_ $classLike): array
    {
        $referencedNames      = [];
        $hasDynamicReference  = false;
        $allNodes             = $nodeFinder->find($classLike->stmts, static fn(): bool => true);
        $ownClassName         = $this->ownClassName($classLike);

        foreach ($allNodes as $node) {
            if (!$node instanceof Expr\ClassConstFetch || !$this->refersToOwnClass($node->class, $ownClassName)) {
                continue;
            }

            $name = $this->constantName($node);

            if ($name === null) {
                $hasDynamicReference = true;
                continue;
            }

            $referencedNames[$name] = true;
        }

        // Literal reads are precise; an unresolved local dynamic fetch makes every private constant ambiguous.
        return ['referencedNames' => $referencedNames, 'hasDynamicReference' => $hasDynamicReference];
    }

    /**
     * Extract a literal class-constant name from a class-constant fetch.
     *
     * @param Expr\ClassConstFetch $node Candidate fetch already known to target the current class-like.
     *
     * @return string|null - constant name when the fetch is literal and not ::class; null for dynamic names
     */
    private function constantName(Expr\ClassConstFetch $node): ?string
    {
        if (!$node->name instanceof Node\Identifier) {
            return null;
        }

        $name = $node->name->toString();

        if (strtolower($name) === 'class') {
            // Foo::class names a class string, not a private constant declaration.
            return null;
        }

        return $name;
    }

    /**
     * Build findings for unused private constants in the dead-code rule.
     *
     * @param AnalysisUnit                $analysisUnit     Supplies the display path stamped on each finding.
     * @param RuleDefinition              $definition       Supplies the rule id, severity, pillar, and tier copied into every finding.
     * @param Class_|Trait_|Enum_         $classLike        Owner whose name prefixes each reported symbol.
     * @param array<string, Node\Const_>  $privateConstants Private constants declared directly on the class-like.
     * @param array<string, true>         $referencedNames  Literal private-constant names read in scope.
     *
     * @return list<Finding> - one finding per private constant whose name never appears in $referencedNames; empty when all are used
     */
    private function findingsForUnusedConstants(
        AnalysisUnit        $analysisUnit,
        RuleDefinition      $definition,
        Class_|Trait_|Enum_ $classLike,
        array               $privateConstants,
        array               $referencedNames,
    ): array {
        $findings  = [];
        $className = $this->resolveClassName($classLike);

        foreach ($privateConstants as $name => $constant) {
            if (isset($referencedNames[$name])) {
                continue;
            }

            $symbol     = sprintf('%s::%s', $className, $name);
            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('Private constant %s is never read.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $constant->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                endLine:     $constant->getEndLine() > 0 ? $constant->getEndLine() : null,
                symbol:      $symbol,
                remediation: 'Remove the unused private constant.',
            );
        }

        // Hand back one finding per private constant whose name was never seen among the referenced names.
        return $findings;
    }

    /**
     * Resolve the declared short name for a class-like node.
     *
     * @param Class_|Trait_|Enum_ $node
     *
     * @return string|null - declared short name, or null for anonymous classes and malformed input
     */
    private function ownClassName(Class_|Trait_|Enum_ $node): ?string
    {
        return $node->name?->toString();
    }

    /**
     * Resolve a display name for a class-like node.
     *
     * @param Class_|Trait_|Enum_ $node
     *
     * @return string - declared class/trait/enum name, or an `@anonymous`/`unknown@line` placeholder when unnamed
     */
    private function resolveClassName(Node $node): string
    {
        if ($node instanceof Class_) {
            // Anonymous classes have no name node, so fall back to a stable placeholder for the symbol string.
            return $node->name?->toString() ?? 'class@anonymous';
        }

        // Traits and enums are always named; the line-tagged fallback only guards against malformed input.
        return $node->name?->toString() ?? sprintf('unknown@%d', $node->getStartLine());
    }

    /**
     * Check whether a class-constant fetch target refers to the current class-like scope.
     *
     * @param Node        $class        Class reference from a constant fetch.
     * @param string|null $ownClassName Enclosing class-like name to compare against, or null when there is none.
     *
     * @return bool - true for self::/static::/$this:: or the enclosing class name; false for dynamic and foreign targets
     */
    private function refersToOwnClass(Node $class, ?string $ownClassName): bool
    {
        if ($class instanceof Expr\Variable) {
            return $class->name === 'this';
        }

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
