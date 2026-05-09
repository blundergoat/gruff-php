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

final readonly class UnusedPrivateMethodRule implements RuleInterface
{
    public const ID = 'dead-code.unused-private-method';

    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__clone', '__toString', '__debugInfo',
        '__get', '__set', '__isset', '__unset', '__call', '__callStatic',
        '__invoke', '__sleep', '__wakeup', '__serialize', '__unserialize',
        '__set_state',
    ];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Unused private method',
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
            $privateMethods = [];
            $calledNames = [];

            foreach ($classLike->stmts as $stmt) {
                if ($stmt instanceof Stmt\ClassMethod && $stmt->isPrivate()) {
                    $name = $stmt->name->toString();

                    if (!in_array($name, self::MAGIC_METHODS, true)) {
                        $privateMethods[$name] = $stmt;
                    }
                }
            }

            if ($privateMethods === []) {
                continue;
            }

            $allNodes = $finder->find($classLike->stmts, static fn (): bool => true);

            foreach ($allNodes as $node) {
                if ($node instanceof Expr\MethodCall
                    && $node->var instanceof Expr\Variable
                    && $node->var->name === 'this'
                    && $node->name instanceof Node\Identifier
                ) {
                    $calledNames[$node->name->toString()] = true;
                }

                if ($node instanceof Expr\StaticCall
                    && ($node->class instanceof Node\Name)
                    && in_array($node->class->toString(), ['self', 'static'], true)
                    && $node->name instanceof Node\Identifier
                ) {
                    $calledNames[$node->name->toString()] = true;
                }

                if ($node instanceof Expr\Array_) {
                    foreach ($node->items as $item) {
                        if ($this->isCallableReference($item->value)) {
                            $name = $this->extractCallableName($item->value);

                            if ($name !== null) {
                                $calledNames[$name] = true;
                            }
                        }
                    }
                }
            }

            $className = $this->resolveClassName($classLike);

            foreach ($privateMethods as $name => $method) {
                if (isset($calledNames[$name])) {
                    continue;
                }

                $symbol = sprintf('%s::%s()', $className, $name);

                $findings[] = new Finding(
                    ruleId: $definition->id,
                    message: sprintf('Private method %s is never called.', $symbol),
                    filePath: $unit->file->displayPath,
                    line: $method->getStartLine(),
                    severity: $definition->defaultSeverity,
                    pillar: $definition->pillar,
                    tier: $definition->tier,
                    confidence: $definition->confidence,
                    endLine: $method->getEndLine() > 0 ? $method->getEndLine() : null,
                    symbol: $symbol,
                    remediation: 'Remove the unused private method.',
                );
            }
        }

        return $findings;
    }

    private function isCallableReference(Expr $expr): bool
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            return false;
        }

        $first = $expr->items[0]->value;
        $second = $expr->items[1]->value;

        return ($first instanceof Expr\Variable && $first->name === 'this')
            && $second instanceof Node\Scalar\String_;
    }

    private function extractCallableName(Expr $expr): ?string
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            return null;
        }

        $second = $expr->items[1]->value;

        return $second instanceof Node\Scalar\String_ ? $second->value : null;
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
}
