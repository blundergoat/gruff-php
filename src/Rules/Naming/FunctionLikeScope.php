<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Describes one isolated function-like naming scope.
 */
final readonly class FunctionLikeScope
{
    /**
     * Capture a function-like node with its immediate parameter and local variable names.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Function-like AST node.
     * @param string                                      $kind - Scope kind: method, function, closure, or arrow.
     * @param array<string, true>                         $parameterNames - Parameter names declared directly by this scope.
     * @param array<string, Variable>                     $localVariables - First local variable node per name in this scope body.
     * @param list<\PhpParser\Node>                       $bodyDescendants - Descendant nodes in this scope body, excluding nested function-like scopes.
     */
    public function __construct(
        public ClassMethod|Function_|Closure|ArrowFunction $node,
        public string $kind,
        public array $parameterNames,
        public array $localVariables,
        public array $bodyDescendants,
    ) {
    }
}
