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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Flags a private class constant that nothing inside its own class ever reads, so the user can delete
 * genuinely dead declarations.
 *
 * Runs per file: for each class, trait, or enum it collects the private constants and every in-scope
 * `self::`/`static::`/`$this::`/`ClassName::` fetch, then reports any private constant whose name is
 * never read. A dynamic fetch it cannot resolve (`self::{$name}`) makes every constant ambiguous, so
 * the whole class is skipped to keep findings high-confidence.
 */
final readonly class UnusedPrivateConstantRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused private constant findings.
     */
    public const ID = 'dead-code.unused-private-constant';

    /**
     * Describes the unused-private-constant rule for the registry and reports.
     *
     * @return RuleDefinition - id, name, pillar, tier, and the default Warning severity callers apply to each finding.
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
     * Reports each private constant that no in-scope read reaches, class by class.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per private constant unreferenced in its class-like; empty when none are dead.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];

        // Check each class, trait, or enum in the file.
        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike NodeIndex query is constrained to class-like classes. */
            $privateConstants = $this->privateConstants($classLike);

            // Nothing to check when the class declares no private constants.
            if ($privateConstants === []) {
                continue;
            }

            $usage = $this->constantUsage($nodeFinder, $classLike);

            // A dynamic fetch we cannot resolve makes every constant ambiguous, so skip the whole class.
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

        return $findings;
    }

    /**
     * Collects the private constants declared directly on one class-like, keyed by name.
     *
     * @param Class_|Trait_|Enum_ $classLike - Class-like declaration whose private constants are collected.
     *
     * @return array<string, Node\Const_> - candidate private declarations keyed by constant name; empty when the class-like declares none.
     */
    private function privateConstants(Class_|Trait_|Enum_ $classLike): array
    {
        $privateConstants = [];

        // Scan the class body for private constant declarations.
        foreach ($classLike->stmts as $stmt) {
            // Only private constant declarations are candidates.
            if (!$stmt instanceof Stmt\ClassConst || !$stmt->isPrivate()) {
                continue;
            }

            // One declaration can define several constants; key each by name.
            foreach ($stmt->consts as $constant) {
                $privateConstants[$constant->name->toString()] = $constant;
            }
        }

        return $privateConstants;
    }

    /**
     * Collects the literal private-constant names read in scope, and whether a dynamic fetch blocks precision.
     *
     * @param NodeFinder          $nodeFinder - Walks the class-like body for constant fetches.
     * @param Class_|Trait_|Enum_ $classLike - Owner whose private constants are being checked.
     *
     * @return array{referencedNames: array<string, true>, hasDynamicReference: bool} - literal names read in scope plus whether an unresolved
     *                                                                                 dynamic local fetch prevents high-confidence findings.
     */
    private function constantUsage(NodeFinder $nodeFinder, Class_|Trait_|Enum_ $classLike): array
    {
        $referencedNames      = [];
        $hasDynamicReference  = false;
        $allNodes             = $nodeFinder->find($classLike->stmts, static fn(): bool => true);
        $ownClassName         = $this->ownClassName($classLike);

        // Walk every node in the class body looking for constant fetches.
        foreach ($allNodes as $node) {
            // Only a fetch targeting this same class counts as an in-scope read.
            if (!$node instanceof Expr\ClassConstFetch || !$this->refersToOwnClass($node->class, $ownClassName)) {
                continue;
            }

            $name = $this->constantName($node);

            // A dynamic name we cannot read leaves every private constant ambiguous.
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
     * Reads the literal constant name from a fetch, or null for a dynamic name or `::class`.
     *
     * @param Expr\ClassConstFetch $node - Candidate fetch already known to target the current class-like.
     *
     * @return string|null - constant name when the fetch is literal and not ::class; null for dynamic names.
     */
    private function constantName(Expr\ClassConstFetch $node): ?string
    {
        // A non-identifier name is dynamic, so there is no literal to read.
        if (!$node->name instanceof Node\Identifier) {
            return null;
        }

        $name = $node->name->toString();

        // Foo::class names a class string, not a private constant declaration.
        if (strtolower($name) === 'class') {
            return null;
        }

        return $name;
    }

    /**
     * Builds one finding per declared private constant whose name never appears among the reads.
     *
     * @param AnalysisUnit                $analysisUnit - Supplies the display path stamped on each finding.
     * @param RuleDefinition              $definition - Supplies the rule id, severity, pillar, and tier copied into every finding.
     * @param Class_|Trait_|Enum_         $classLike - Owner whose name prefixes each reported symbol.
     * @param array<string, Node\Const_>  $privateConstants - Private constants declared directly on the class-like.
     * @param array<string, true>         $referencedNames - Literal private-constant names read in scope.
     *
     * @return list<Finding> - one finding per private constant whose name never appears in $referencedNames; empty when all are used.
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

        // Report each declared constant that no read referenced.
        foreach ($privateConstants as $name => $constant) {
            // A constant that was read is live, so skip it.
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

        return $findings;
    }

    /**
     * Returns the class-like's declared short name, or null for an anonymous class.
     *
     * @param Class_|Trait_|Enum_ $node - Class-like declaration whose short name is used for self-reference checks.
     *
     * @return string|null - declared short name, or null for anonymous classes and malformed input.
     */
    private function ownClassName(Class_|Trait_|Enum_ $node): ?string
    {
        return $node->name?->toString();
    }

    /**
     * Returns a display name for the class-like, using a stable placeholder when it is unnamed.
     *
     * @param Class_|Trait_|Enum_ $node - Class-like declaration whose display symbol is needed for finding text.
     *
     * @return string - declared class/trait/enum name, or an `@anonymous`/`unknown@line` placeholder when unnamed.
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
     * Reports whether a constant-fetch target points back at the enclosing class-like scope.
     *
     * @param Node        $class - Class reference from a constant fetch.
     * @param string|null $ownClassName - Enclosing class-like name to compare against, or null when there is none.
     *
     * @return bool - true for self::/static::/$this:: or the enclosing class name; false for dynamic and foreign targets.
     */
    private function refersToOwnClass(Node $class, ?string $ownClassName): bool
    {
        // A `$this::` fetch counts only when the variable really is `$this`.
        if ($class instanceof Expr\Variable) {
            return $class->name === 'this';
        }

        // A non-name target (a dynamic expression) is not a self-reference.
        if (!$class instanceof Node\Name) {
            return false;
        }

        $reference = strtolower($class->toString());

        // `self::` and `static::` always refer to the current class.
        if ($reference === 'self' || $reference === 'static') {
            return true;
        }

        // Otherwise it matches only when the referenced name equals this class's own name.
        return $ownClassName !== null && strtolower($class->getLast()) === strtolower($ownClassName);
    }
}
