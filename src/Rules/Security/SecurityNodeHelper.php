<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Provides shared AST helpers for security rules.
 */
final class SecurityNodeHelper
{
    /**
     * List PHP superglobals treated as request-controlled input.
     *
     * @return list<string> - superglobal variable names without the leading `$`, treated as tainted sources
     */
    public static function userInputSuperglobals(): array
    {
        // The canonical set every taint check treats as attacker-controlled; keep $_ENV/$_SESSION out by design.
        return ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', '_FILES'];
    }

    /**
     * Match the assignment shapes local taint tracking understands.
     *
     * This decides which writes every security sink sees when a user runs, for example,
     * `gruff-php analyse src --include-rule security.header-injection`. Concat assignment
     * is tracked because appending request input taints a value exactly like assigning it.
     * Reference assignment is deliberately NOT tracked: `=&` creates an alias whose later
     * writes flow both ways, which last-write-wins tracking would classify backwards, so
     * aliased flows stay out of scope rather than mislead the user. Arithmetic operators
     * stay out until a concrete sink justifies them.
     *
     * @param Node $node - Candidate node from a scope walk.
     *
     * @return bool - true for plain assignment or concat assignment nodes
     */
    public static function isTaintTrackedAssignment(Node $node): bool
    {
        return $node instanceof Expr\Assign
            || $node instanceof Expr\AssignOp\Concat;
    }

    /**
     * Resolve the plain local variable a taint-tracked assignment writes to.
     *
     * @param Expr\Assign|Expr\AssignOp\Concat $assignment - Assignment node from a scope walk.
     *
     * @return string|null - the written variable name, or null for property/offset/dynamic targets that local
     *                     taint tracking does not model
     */
    public static function assignmentTargetName(Expr\Assign|Expr\AssignOp\Concat $assignment): ?string
    {
        return $assignment->var instanceof Expr\Variable && is_string($assignment->var->name)
            ? $assignment->var->name
            : null;
    }

    /**
     * Resolve a non-namespaced function call to its lower-case name.
     *
     * @param FuncCall $call - Function call node to inspect.
     *
     * @return string|null - lower-cased global function name, or null for dynamic or namespaced calls that cannot match
     */
    public static function globalFunctionName(FuncCall $call): ?string
    {
        if (!$call->name instanceof Name) {
            // A dynamic callee (variable/expression) has no static name to match against the rule's allowlist.
            return null;
        }

        $parts = $call->name->getParts();
        if (count($parts) !== 1) {
            // Namespaced calls are not the global builtins these rules guard, so they never match.
            return null;
        }

        // Lower-case because PHP function names are case-insensitive; callers compare against lower-case literals.
        return strtolower($parts[0]);
    }

    /**
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args - Call argument nodes to inspect.
     * @param int                                                  $index - Zero-based argument index.
     *
     * @return Expr|null - unwrapped argument value at the index, or null when the slot is absent or a variadic spread
     */
    public static function argumentValue(array $args, int $index): ?Expr
    {
        $arg = $args[$index] ?? null;
        if (!$arg instanceof Node\Arg) {
            return null;
        }

        return $arg->value;
    }

    /**
     * Resolve a constant fetch to its normalized constant name.
     *
     * @param Node $node - Node to inspect.
     *
     * @return string|null - upper-cased constant name, or null for namespaced or non-constant-fetch expressions
     */
    public static function constantName(Node $node): ?string
    {
        if (!$node instanceof Expr\ConstFetch) {
            // Only a bare constant fetch carries a name; anything else has no constant to normalise.
            return null;
        }

        $parts = $node->name->getParts();
        if (count($parts) !== 1) {
            // Namespaced constants are out of scope for these flag checks, so report no name.
            return null;
        }

        // Upper-case because PHP constant names are case-sensitive but callers compare against UPPER literals.
        return strtoupper($parts[0]);
    }

    /**
     * Determine whether a node statically represents a false-like value.
     *
     * @param Node $node - Node to inspect.
     *
     * @return bool - true when the node is the literal `false` or integer 0 (the disabled-flag values rules look for)
     */
    public static function isFalseLike(Node $node): bool
    {
        if ($node instanceof Expr\ConstFetch) {
            // The literal `false` keyword (case-insensitive) is the disabled-flag value rules look for.
            return strtolower($node->name->toString()) === 'false';
        }

        if ($node instanceof Scalar\Int_) {
            // Integer 0 is the other common "off" literal, e.g. CURLOPT_SSL_VERIFYPEER set to 0.
            return $node->value === 0;
        }

        // Variables, constants, and computed values are not statically known to be false-like.
        return false;
    }

    /**
     * Detect whether an expression reads from user-input superglobals.
     *
     * Also follows simple same-scope local assignments before the inspected
     * expression so sinks catch `$next = $_GET["next"]; header($next);`.
     *
     * @param Node $node - Node tree to inspect.
     *
     * @return bool - true when the tree reads request input directly or via a same-scope laundered local; false if clean
     */
    public static function containsUserInput(Node $node): bool
    {
        if (self::containsDirectUserInput($node)) {
            // A direct superglobal read is request-tainted with no further analysis needed.
            return true;
        }

        // Otherwise fall back to the costlier same-scope flow analysis for laundered locals.
        return self::containsTaintedLocal($node);
    }

    /**
     * Detect direct reads from request superglobals within a node tree.
     *
     * @param Node $node - Node tree to inspect.
     *
     * @return bool - true when the tree directly reads a request superglobal; false ignores laundered locals
     */
    private static function containsDirectUserInput(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
                // A variable whose name is one of the request superglobals is the attacker-controlled source.
                return $candidate instanceof Expr\Variable
                       && is_string($candidate->name)
                       && in_array($candidate->name, self::userInputSuperglobals(), true);
            }) instanceof Node;
    }

    /**
     * Detect local variables that were assigned request data earlier in the same scope.
     *
     * @param Node $node - Node tree to inspect.
     *
     * @return bool - true when the node reads a local filled from request input by an earlier same-scope assignment
     */
    private static function containsTaintedLocal(Node $node): bool
    {
        $referencedVariables = self::referencedVariableNames($node);
        if ($referencedVariables === []) {
            // No local variables to trace, so there is nothing that could have been laundered from a superglobal.
            return false;
        }

        $scope = self::enclosingFunctionLike($node);
        if (!$scope instanceof FunctionLike) {
            // Without an owning function body the same-scope flow analysis has no statements to walk.
            return false;
        }

        $statements = $scope->getStmts();
        if ($statements === null) {
            // Abstract or interface methods have no body, so no assignment could taint a local here.
            return false;
        }

        $sinkPosition = $node->getStartFilePos();
        if ($sinkPosition < 0) {
            // A missing byte offset means we cannot order assignments before the sink; stay safe and bail.
            return false;
        }

        $taintedVariables = self::taintedVariableNamesBefore(array_values($statements), $scope, $sinkPosition);

        foreach ($referencedVariables as $variableName) {
            if (isset($taintedVariables[$variableName])) {
                // The sink reads a local that an earlier same-scope assignment filled from request input.
                return true;
            }
        }

        // None of the referenced locals were tainted before the sink, so the expression is clean.
        return false;
    }

    /**
     * @param list<Node\Stmt> $statements - Statements in the owning function-like scope, walked up to the sink position.
     * @param FunctionLike    $scope - Scope that owns the sink expression.
     * @param int             $sinkPosition - Byte offset of the sink expression.
     *
     * @return array<string, true> - set of local variable names tainted at the sink, keyed by name; empty when none
     */
    private static function taintedVariableNamesBefore(array $statements, FunctionLike $scope, int $sinkPosition): array
    {
        $taintedVariables = [];
        $nodeFinder       = new NodeFinder();
        $assignments      = $nodeFinder->find(
            $statements,
            static fn(Node $candidate): bool => self::isTaintTrackedAssignment($candidate)
                                                && $candidate->getStartFilePos() >= 0
                                                && $candidate->getStartFilePos() < $sinkPosition,
        );

        // Replay each write in source order so the taint state at the sink reflects what really ran.
        foreach ($assignments as $assignment) {
            // Narrow to the tracked assignment shapes; the finder predicate already matched them.
            if (!$assignment instanceof Expr\Assign && !$assignment instanceof Expr\AssignOp\Concat) {
                continue;
            }

            // Writes inside nested closures cannot affect this scope's locals at the sink.
            if (self::enclosingFunctionLike($assignment) !== $scope) {
                continue;
            }

            $variableName = self::assignmentTargetName($assignment);
            // Property and array-offset targets are beyond same-scope local tracking; skip them.
            if ($variableName === null) {
                continue;
            }

            // Request data on the right side, direct or via an already-tainted local, taints the target.
            if (
                self::containsDirectUserInput($assignment->expr)
                || self::hasTaintedVariableReference($assignment->expr, $taintedVariables)
            ) {
                $taintedVariables[$variableName] = true;
                continue;
            }

            // A clean concat append neither taints nor cleans: the variable keeps whatever it held.
            if ($assignment instanceof Expr\AssignOp\Concat) {
                continue;
            }

            unset($taintedVariables[$variableName]);
        }

        // Taint state evaluated at the sink: because assignments are walked in source order, the last write
        // to a variable wins, so a clean reassignment that overwrote earlier request data leaves it absent.
        return $taintedVariables;
    }

    /**
     * Check whether a node references a known tainted variable.
     *
     * @param Node                $node - Node tree to inspect.
     * @param array<string, true> $taintedVariables - Tainted local-variable names known at the current sink or assignment.
     *
     * @return bool - true when the tree reads any name in $taintedVariables, propagating taint to this expression
     */
    private static function hasTaintedVariableReference(Node $node, array $taintedVariables): bool
    {
        foreach (self::referencedVariableNames($node) as $variableName) {
            if (isset($taintedVariables[$variableName])) {
                // The expression reads an already-tainted local, so taint propagates to this assignment.
                return true;
            }
        }

        // The expression touches no known-tainted local, so it does not carry request input forward.
        return false;
    }

    /**
     * Return non-superglobal variable names referenced by a node tree.
     *
     * @param Node $node - Node tree to inspect.
     *
     * @return list<string> - deduplicated local variable names touched by the tree, superglobals excluded; empty when none
     */
    private static function referencedVariableNames(Node $node): array
    {
        $names      = [];
        $nodeFinder = new NodeFinder();
        $variables  = $nodeFinder->find($node, static fn(Node $candidate): bool => $candidate instanceof Expr\Variable);

        foreach ($variables as $variable) {
            if (!$variable instanceof Expr\Variable) {
                continue;
            }

            if (is_string($variable->name) && !in_array($variable->name, self::userInputSuperglobals(), true)) {
                $names[$variable->name] = true;
            }
        }

        // Deduplicated local names only; superglobals are excluded since they are sources, not laundered locals.
        return array_keys($names);
    }

    /**
     * Collect the identities of every ancestor between a node and its scope boundary.
     *
     * @param Node      $node - Node whose ancestor chain is captured (typically a sink call).
     * @param Node|null $scopeBoundary - Enclosing function-like to stop at, or null to walk to the file root.
     *
     * @return array<int, true> - set of ancestor object ids, so a write can test whether it shares the sink's branches
     */
    public static function ancestorIdsWithin(Node $node, ?Node $scopeBoundary): array
    {
        $ancestorIds = [];
        $parent      = $node->getAttribute('parent');

        // Record every ancestor up to (excluding) the scope boundary.
        while ($parent instanceof Node && $parent !== $scopeBoundary) {
            $ancestorIds[spl_object_id($parent)] = true;
            $parent                              = $parent->getAttribute('parent');
        }

        return $ancestorIds;
    }

    /**
     * Decide whether the runtime could reach a sink without executing this node.
     *
     * A write inside a branch the sink does not share may be skipped on the path that
     * reaches the sink, so trackers let such writes add evidence toward a finding but
     * never erase what an earlier write established. A write inside the sink's own
     * branch chain always runs before the sink and counts as unconditional.
     *
     * @param Node            $node - Write/event node whose reachability is being classified.
     * @param Node            $sink - Sink node whose runtime path must be reached.
     * @param Node|null       $scopeBoundary - Enclosing function-like boundary, or null for file scope.
     * @param array<int, true> $sinkAncestorIds - Ancestor-id set of the sink, from ancestorIdsWithin().
     *
     * @return bool - true when a branch, loop, catch, match/ternary arm, or short-circuit operator that the
     *              sink does not share sits between the node and the scope boundary
     */
    public static function isSkippableBeforeSink(Node $node, Node $sink, ?Node $scopeBoundary, array $sinkAncestorIds): bool
    {
        $parent = $node->getAttribute('parent');

        // Climb toward the scope boundary looking for a skipping construct outside the sink's own chain.
        while ($parent instanceof Node && $parent !== $scopeBoundary) {
            $isSkippingConstruct = $parent instanceof Stmt\If_
                || $parent instanceof Stmt\ElseIf_
                || $parent instanceof Stmt\Else_
                || $parent instanceof Stmt\Switch_
                || $parent instanceof Stmt\Case_
                || $parent instanceof Stmt\While_
                || $parent instanceof Stmt\Do_
                || $parent instanceof Stmt\For_
                || $parent instanceof Stmt\Foreach_
                || $parent instanceof Stmt\Catch_
                || $parent instanceof Expr\Ternary
                || $parent instanceof Expr\Match_
                || $parent instanceof Expr\BinaryOp\BooleanAnd
                || $parent instanceof Expr\BinaryOp\BooleanOr
                || $parent instanceof Expr\BinaryOp\Coalesce;

            if ($isSkippingConstruct) {
                $parentId = spl_object_id($parent);

                // A construct the sink also sits inside cannot separate the write from the sink's path,
                // unless the write and sink live in sibling branches of that shared construct.
                if (!isset($sinkAncestorIds[$parentId])) {
                    return true;
                }

                if ($parent instanceof Stmt\If_ && self::ifBranchesDiffer($node, $sink, $parent)) {
                    return true;
                }
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Decide whether two nodes sit in different executable branches of the same if-chain.
     *
     * @param Node     $node - Write/event node being classified.
     * @param Node     $sink - Sink node whose path is being compared.
     * @param Stmt\If_ $if - Shared if-chain ancestor.
     *
     * @return bool - true when both nodes are in known sibling bodies of the if-chain
     */
    private static function ifBranchesDiffer(Node $node, Node $sink, Stmt\If_ $if): bool
    {
        $nodeBranch = self::ifBranchKey($node, $if);
        $sinkBranch = self::ifBranchKey($sink, $if);

        return $nodeBranch !== null && $sinkBranch !== null && $nodeBranch !== $sinkBranch;
    }

    /**
     * Identify the body branch a node belongs to for a specific if-chain.
     *
     * Conditions return null because they are evaluated before any reachable branch body and
     * should not be treated as skippable relative to a sink inside that same branch chain.
     *
     * @param Node     $node - Descendant candidate.
     * @param Stmt\If_ $if - If-chain ancestor to classify against.
     *
     * @return string|null - stable branch key for if/elseif/else bodies, or null for conditions/unrelated nodes
     */
    private static function ifBranchKey(Node $node, Stmt\If_ $if): ?string
    {
        $current = $node;
        $parent  = $current->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent === $if) {
                if (in_array($current, $if->stmts, true)) {
                    return 'if';
                }

                foreach ($if->elseifs as $elseIf) {
                    if ($current === $elseIf) {
                        return 'elseif:' . spl_object_id($elseIf);
                    }
                }

                if ($if->else instanceof Stmt\Else_ && $current === $if->else) {
                    return 'else:' . spl_object_id($if->else);
                }

                return null;
            }

            $current = $parent;
            $parent  = $current->getAttribute('parent');
        }

        return null;
    }

    /**
     * Find the function, method, or closure scope containing a node.
     *
     * @param Node $node - Node whose containing function-like scope is needed.
     *
     * @return FunctionLike|null - nearest enclosing function/method/closure, or null when the node lives at file scope
     */
    public static function enclosingFunctionLike(Node $node): ?FunctionLike
    {
        $current = $node;
        while ($current instanceof Node) {
            if ($current instanceof FunctionLike) {
                // The nearest enclosing function/method/closure; this is the scope taint analysis is bounded to.
                return $current;
            }

            $parent  = $current->getAttribute('parent');
            $current = $parent instanceof Node ? $parent : null;
        }

        // Walked past the top of the tree without a function-like ancestor: the node lives at file scope.
        return null;
    }

    /**
     * Detect string construction patterns that can hide unsafe concatenation.
     *
     * @param Node $node - Node tree to inspect.
     *
     * @return bool - true when the tree builds a string via `.` concatenation or interpolation, which can splice in untrusted data
     */
    public static function containsConcatOrInterpolation(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
                // A `.` concatenation or a "$var" interpolated string is the dynamic-construction signal.
                return $candidate instanceof Expr\BinaryOp\Concat
                       || $candidate instanceof Scalar\Encapsed;
            }) instanceof Node;
    }

    /**
     * Identify literal string nodes for security-rule exemptions.
     *
     * @param Node $node - Node to inspect.
     *
     * @return bool - true when the node is a literal string scalar, so rules can exempt statically-trusted constant args
     */
    public static function isStringLiteral(Node $node): bool
    {
        return $node instanceof Scalar\String_;
    }

    /**
     * Build the display name used when reporting a function call.
     *
     * @param FuncCall $call - Function call node to describe.
     *
     * @return string - the resolved function name, or the label "dynamic function call" so findings never show an empty name
     */
    public static function functionNameForMessage(FuncCall $call): string
    {
        $name = self::globalFunctionName($call);

        return $name ?? 'dynamic function call';
    }

    /**
     * Resolve a method name to its lower-case string when statically known.
     *
     * @param Expr\MethodCall|Expr\StaticCall $call - Call node to inspect.
     *
     * @return string|null - lower-cased method name, or null when the name is computed (e.g. $obj->$method())
     */
    public static function methodName(Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        if (!$call->name instanceof Identifier) {
            return null;
        }

        return strtolower($call->name->toString());
    }

    /**
     * Resolve a class node to a lower-case class name when statically known.
     *
     * @param Node $class - Class node from a new/static call.
     *
     * @return string|null - lower-cased class name, FQCN-resolved when available, or null for dynamic/anonymous classes
     */
    public static function className(Node $class): ?string
    {
        if (!$class instanceof Name) {
            // Anonymous classes and dynamic `new $cls` have no static name to resolve.
            return null;
        }

        $resolvedName = $class->getAttribute('resolvedName');
        if ($resolvedName instanceof Name) {
            // Prefer the import/namespace-resolved FQCN so short aliases match their fully-qualified target.
            return strtolower($resolvedName->toString());
        }

        // No resolver attribute (unresolved pass): fall back to the name as written in source.
        return strtolower($class->toString());
    }

    /**
     * Match a class node against exact FQCNs or short class names.
     *
     * @param Node         $class - Class node from a new/static call.
     * @param list<string> $classNames - FQCNs or short class names to match.
     *
     * @return bool - true when the resolved class equals a target FQCN or shares a short target's final namespace segment
     */
    public static function hasMatchingClassName(Node $class, array $classNames): bool
    {
        // Matching is deliberately name-based (short names match FQCN tails and vice versa): a userland
        // class sharing a built-in's short name matches too. Callers accept name evidence, not resolved
        // types, so imported and fully-qualified spellings keep matching without a name-resolution pass.
        $resolvedName = self::className($class);
        if ($resolvedName === null) {
            // An unresolvable class name can never equal a configured target, so it cannot match.
            return false;
        }

        foreach ($classNames as $className) {
            $normalized = strtolower(ltrim($className, '\\'));
            if ($resolvedName === $normalized) {
                return true;
            }

            if (!str_contains($normalized, '\\') && str_ends_with($resolvedName, '\\' . $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect whether a node tree contains an HTTP(S) literal.
     *
     * @param Node $node - Node tree to inspect.
     *
     * @return bool - true when a literal string in the tree starts with http:// or https://, used to gate URL-only sinks
     */
    public static function containsUrlLiteral(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
                if (!$candidate instanceof Scalar\String_) {
                    // Only literal strings can be inspected for a scheme; non-literals are never a URL match here.
                    return false;
                }

                // Match literal outbound URL strings for URL-only sinks.
                return preg_match('/^https?:\/\//i', $candidate->value) === 1;
            }) instanceof Node;
    }

    /**
     * Detect whether an expression references likely sensitive data.
     *
     * @param Node $node - Node tree to inspect.
     *
     * @return bool - true when a variable name, property, array key, string literal, or env read in the tree names a secret
     */
    public static function containsSensitiveReference(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
                if ($candidate instanceof Expr\Variable && is_string($candidate->name)) {
                    // A variable name like $apiKey signals secret context.
                    return self::hasSensitiveContext($candidate->name);
                }

                if ($candidate instanceof Expr\PropertyFetch && $candidate->name instanceof Identifier) {
                    // A property access like $config->secret signals secret context.
                    return self::hasSensitiveContext($candidate->name->toString());
                }

                if ($candidate instanceof Expr\ArrayDimFetch && $candidate->dim instanceof Scalar\String_) {
                    // A literal array key like $env['password'] signals secret context.
                    return self::hasSensitiveContext($candidate->dim->value);
                }

                if ($candidate instanceof Scalar\String_) {
                    // A bare string literal whose text itself names a secret signals secret context.
                    return self::hasSensitiveContext($candidate->value);
                }

                if ($candidate instanceof Expr\FuncCall) {
                    // A call may be an env reader pulling a secret-named key; defer to that check.
                    return self::isSensitiveEnvironmentRead($candidate);
                }

                // Node kinds we do not classify carry no secret context.
                return false;
            }) instanceof Node;
    }

    /**
     * Detect env-reader calls that request a sensitive key.
     *
     * @param FuncCall $call - Call node to inspect; only env readers (getenv/env/apache_getenv) are considered.
     *
     * @return bool - true when an env reader requests a secret-like key, or reads the whole environment via no argument
     */
    private static function isSensitiveEnvironmentRead(FuncCall $call): bool
    {
        $name = self::globalFunctionName($call);
        if ($name === null || !in_array($name, ['apache_getenv', 'env', 'getenv'], true)) {
            // Not one of the recognised env readers, so it cannot be a sensitive environment lookup.
            return false;
        }

        $firstArg = self::argumentValue($call->args, 0);
        if ($firstArg === null) {
            // A no-argument getenv() returns the whole environment, which is treated as sensitive wholesale.
            return true;
        }

        // A literal key argument is sensitive only when its text matches the secret-name pattern.
        return $firstArg instanceof Scalar\String_ && self::hasSensitiveContext($firstArg->value);
    }

    /**
     * Detect secret-like words in identifiers or string keys.
     *
     * @param string $contextText - Identifier or string-key text to scan; the value itself, never a read secret.
     *
     * @return bool - true when the text matches the secret-name pattern (api key, token, password, secret, etc.)
     */
    private static function hasSensitiveContext(string $contextText): bool
    {
        return preg_match('/(?:api[_-]?key|auth(?:orization)?|cookie|pass(?:word|wd)?|private[_-]?key|secret|token)/i', $contextText) === 1;
    }
}
