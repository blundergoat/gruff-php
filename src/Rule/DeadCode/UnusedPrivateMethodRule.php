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

/**
 * Detects private methods that are never called within their class-like scope.
 */
final readonly class UnusedPrivateMethodRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused private method findings.
     */
    public const ID = 'dead-code.unused-private-method';

    /**
     * Magic methods excluded because PHP calls them through language hooks.
     */
    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__clone', '__toString', '__debugInfo',
        '__get', '__set', '__isset', '__unset', '__call', '__callStatic',
        '__invoke', '__sleep', '__wakeup', '__serialize', '__unserialize',
        '__set_state',
    ];

    /**
     * Describe the unused private method rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unused private method',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find private methods that are not referenced inside their class-like scope.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for unused private methods.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $classLikes = $nodeFinder->find($analysisUnit->statements, static function (Node $node): bool {
            return $node instanceof Class_
                || $node instanceof Trait_
                || $node instanceof Enum_;
        });

        $findings = [];

        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike Finder predicate restricts results to class-like declarations. */
            $privateMethods = $this->privateMethods($classLike);

            if ($privateMethods === []) {
                continue;
            }

            $calledNames = $this->calledPrivateMethodNames($nodeFinder, $classLike);
            $findings    = array_merge(
                $findings,
                $this->findingsForUnusedMethods(
                    analysisUnit:           $analysisUnit,
                    definition:     $definition,
                    classLike:      $classLike,
                    privateMethods: $privateMethods,
                    calledNames:    $calledNames,
                ),
            );
        }

        return $findings;
    }

    /**
     * @param Class_|Trait_|Enum_ $classLike
     * @return array<string, Stmt\ClassMethod>
     */
    private function privateMethods(Class_|Trait_|Enum_ $classLike): array
    {
        $privateMethods = [];

        foreach ($classLike->stmts as $stmt) {
            if (!$stmt instanceof Stmt\ClassMethod || !$stmt->isPrivate()) {
                continue;
            }

            $name = $stmt->name->toString();
            if (!in_array($name, self::MAGIC_METHODS, true)) {
                $privateMethods[$name] = $stmt;
            }
        }

        return $privateMethods;
    }

    /**
     * @param Class_|Trait_|Enum_ $classLike
     * @return array<string, true>
     */
    private function calledPrivateMethodNames(NodeFinder $finder, Class_|Trait_|Enum_ $classLike): array
    {
        $calledNames = [];
        $allNodes    = $finder->find($classLike->stmts, static fn (): bool => true);

        foreach ($allNodes as $node) {
            $name = $this->calledMethodName($node) ?? $this->callableArrayName($node);

            if ($name !== null) {
                $calledNames[$name] = true;
            }
        }

        return $calledNames;
    }

    /**
     * Extract a private method name from `$this` or self/static calls.
     *
     * @return string|null Called method name, or null for unsupported calls.
     */
    private function calledMethodName(Node $node): ?string
    {
        if ($node instanceof Expr\MethodCall
            && $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier
        ) {
            return $node->name->toString();
        }

        if ($node instanceof Expr\StaticCall
            && ($node->class instanceof Node\Name)
            && in_array($node->class->toString(), ['self', 'static'], true)
            && $node->name instanceof Node\Identifier
        ) {
            return $node->name->toString();
        }

        return null;
    }

    /**
     * Extract a method name from callable-array syntax.
     *
     * @return string|null Callable method name, or null when the node is not supported.
     */
    private function callableArrayName(Node $node): ?string
    {
        if (!$node instanceof Expr\Array_) {
            return null;
        }

        foreach ($node->items as $item) {
            if ($this->isCallableReference($item->value)) {
                return $this->extractCallableName($item->value);
            }
        }

        return null;
    }

    /**
     * @param Class_|Trait_|Enum_             $classLike
     * @param array<string, Stmt\ClassMethod> $privateMethods
     * @param array<string, true>             $calledNames
     * @return list<Finding>
     */
    private function findingsForUnusedMethods(
        AnalysisUnit $analysisUnit,
        RuleDefinition $definition,
        Class_|Trait_|Enum_ $classLike,
        array $privateMethods,
        array $calledNames,
    ): array {
        $findings  = [];
        $className = $this->resolveClassName($classLike);

        foreach ($privateMethods as $name => $method) {
            if (isset($calledNames[$name])) {
                continue;
            }

            $symbol     = sprintf('%s::%s()', $className, $name);
            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('Private method %s is never called.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $method->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                endLine:     $method->getEndLine() > 0 ? $method->getEndLine() : null,
                symbol:      $symbol,
                remediation: 'Remove the unused private method.',
            );
        }

        return $findings;
    }

    /**
     * Check whether an expression is a `$this` callable-array reference.
     *
     * @return bool True when the expression is a supported callable reference.
     */
    private function isCallableReference(Expr $expr): bool
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            return false;
        }

        $first  = $expr->items[0]->value;
        $second = $expr->items[1]->value;

        return ($first instanceof Expr\Variable && $first->name === 'this')
            && $second instanceof Node\Scalar\String_;
    }

    /**
     * Extract the method name from a supported callable-array expression.
     *
     * @return string|null Callable method name, or null when not available.
     */
    private function extractCallableName(Expr $expr): ?string
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            return null;
        }

        $second = $expr->items[1]->value;

        return $second instanceof Node\Scalar\String_ ? $second->value : null;
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
}
