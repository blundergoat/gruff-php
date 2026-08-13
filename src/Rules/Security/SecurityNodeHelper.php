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
 * Shared AST helpers that back every security rule - taint tracking, receiver/class resolution, argument
 * unwrapping, and the branch-reachability model that decides when an earlier write can hide a finding.
 *
 * Rules delegate here so `gruff-php analyse --pillar security` applies one consistent notion of "request
 * input", "same-scope taint", and "skippable write" across header injection, XSS, SSRF, and the rest.
 * All methods are static and side-effect free; taint analysis is bounded to a single function-like scope.
 */
final class SecurityNodeHelper
{
    /**
     * Global sink functions mapped to the parameter names PHP declares for them, in declared order.
     *
     * PHP 8 accepts named arguments for internal functions, so `header(header: $target)` puts the value a
     * rule reads in no positional slot at all. Position-only matching resolves that call to null and the
     * sink goes unreported, which is why every function a security rule reads an argument from is listed
     * here: `sinkArgumentValue()` asks for the slot by name first, then falls back to position. Each list
     * carries the leading parameters the rules actually read, not every trailing option.
     *
     * Each slot lists every spelling that resolves it. Bundled extensions were checked against PHP's own
     * reflected signatures; the handful that ship no reflectable declaration here (`sqlsrv_query`) list every
     * documented spelling instead of betting on one, which costs nothing because a name that does not exist
     * simply never matches.
     *
     * @var array<string, list<list<string>>>
     */
    private const SINK_PARAMETERS = [
        'apache_getenv'         => [['variable']],
        'assert'                => [['assertion']],
        'chdir'                 => [['directory']],
        'compact'               => [['var_name']],
        'copy'                  => [['from'], ['to']],
        'curl_init'             => [['url']],
        'curl_setopt'           => [['handle'], ['option'], ['value']],
        'curl_setopt_array'     => [['handle'], ['options']],
        'dirname'               => [['path'], ['levels']],
        'env'                   => [['key']],
        'exec'                  => [['command']],
        'extract'               => [['array']],
        'file_get_contents'     => [['filename']],
        'file_put_contents'     => [['filename'], ['data']],
        'fopen'                 => [['filename']],
        'getenv'                => [['name']],
        'glob'                  => [['pattern']],
        'header'                => [['header']],
        'ini_set'               => [['option'], ['value']],
        'mkdir'                 => [['directory']],
        'mysql_query'           => [['query']],
        'mysqli_query'          => [['mysql'], ['query']],
        'oci_parse'             => [['connection'], ['sql']],
        'passthru'              => [['command']],
        'pg_query'              => [['connection'], ['query']],
        'popen'                 => [['command']],
        'proc_open'             => [['command']],
        'readfile'              => [['filename']],
        'realpath'              => [['path']],
        'rename'                => [['from'], ['to']],
        'rmdir'                 => [['directory']],
        'scandir'               => [['directory']],
        'shell_exec'            => [['command']],
        'simplexml_load_file'   => [['filename']],
        'simplexml_load_string' => [['data']],
        // Microsoft documents `tsql`; the PHPStan stub bundled here declares `sql`. Neither is reflectable.
        'sqlsrv_query'          => [['conn'], ['sql', 'tsql']],
        'system'                => [['command']],
        'unlink'                => [['filename']],
        'unserialize'           => [['data'], ['options']],
    ];

    /**
     * Lists the PHP superglobals treated as request-controlled input.
     *
     * @return list<string> - superglobal variable names without the leading `$`, treated as tainted sources
     */
    public static function userInputSuperglobals(): array
    {
        // The canonical set every taint check treats as attacker-controlled; keep $_ENV/$_SESSION out by design.
        return ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', '_FILES'];
    }

    /**
     * Reports whether a node is one of the assignment shapes local taint tracking understands.
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
     * Resolves the plain local variable a taint-tracked assignment writes to.
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
     * Resolves a non-namespaced function call to its lower-case name.
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
     * Returns one call argument by its accepted parameter names or positional index.
     *
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args - Call arguments; an empty list means the
     *                                                                    caller omitted every parameter.
     * @param int                                                  $index - Zero-based position among unnamed
     *                                                                    arguments.
     * @param list<string>                                         $parameterNames - Accepted named-argument labels;
     *                                                                    empty keeps positional-only matching.
     *
     * @return Expr|null - Argument value, or null when the parameter is absent or represented by a variadic placeholder.
     */
    public static function argumentValue(array $args, int $index, array $parameterNames = []): ?Expr
    {
        $acceptedNames = array_fill_keys(array_map(strtolower(...), $parameterNames), true);

        // A named parameter keeps its API meaning even when the caller reorders the source arguments.
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || !$arg->name instanceof Identifier) {
                continue;
            }

            if (isset($acceptedNames[strtolower($arg->name->toString())])) {
                return $arg->value;
            }
        }

        $positionalIndex = 0;
        // Named arguments do not consume positional slots, so mixed calls retain the declared parameter order.
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->name instanceof Identifier) {
                continue;
            }

            if ($positionalIndex === $index) {
                return $arg->value;
            }

            ++$positionalIndex;
        }

        return null;
    }

    /**
     * Returns one argument of a global function call, matching the parameter name PHP declares for that slot.
     *
     * Rules call this instead of `argumentValue()` whenever the callee is a global function, so a sink written
     * with named arguments resolves to the same expression as the positional form. An unmapped function or a
     * dynamic callee simply keeps positional matching.
     *
     * @param FuncCall $call - Global function call whose argument is being read.
     * @param int      $index - Zero-based parameter position the rule wants.
     *
     * @return Expr|null - Argument value, or null when the call omits that parameter entirely
     */
    public static function sinkArgumentValue(FuncCall $call, int $index): ?Expr
    {
        $functionName = self::globalFunctionName($call);
        // Without a mapped declaration there is no name to match on, so the positional path decides alone.
        $parameterNames = $functionName === null ? [] : (self::SINK_PARAMETERS[$functionName][$index] ?? []);

        return self::argumentValue($call->args, $index, $parameterNames);
    }

    /**
     * Resolves a constant fetch to its normalized constant name.
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
     * Reports whether a node statically represents a false-like value.
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
     * Reports whether an expression reads from user-input superglobals.
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
     * Reports whether a node tree directly reads a request superglobal.
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
     * Reports whether a node reads a local that was assigned request data earlier in the same scope.
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

        // A sink that reads any tainted local is carrying laundered request data.
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
     * Computes the set of local variable names tainted before the sink position.
     *
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
     * Reports whether a node references a known tainted variable.
     *
     * @param Node                $node - Node tree to inspect.
     * @param array<string, true> $taintedVariables - Tainted local-variable names known at the current sink or assignment.
     *
     * @return bool - true when the tree reads any name in $taintedVariables, propagating taint to this expression
     */
    private static function hasTaintedVariableReference(Node $node, array $taintedVariables): bool
    {
        // Any referenced name that is already tainted pulls request data into this expression.
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
     * Returns the non-superglobal variable names referenced by a node tree.
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

        // Collect every variable leaf the finder returned beneath the tree.
        foreach ($variables as $variable) {
            // Guard the finder's loose node type before reading the name.
            if (!$variable instanceof Expr\Variable) {
                continue;
            }

            // Keep plainly named locals only; superglobals are sources, not laundered aliases.
            if (is_string($variable->name) && !in_array($variable->name, self::userInputSuperglobals(), true)) {
                $names[$variable->name] = true;
            }
        }

        // Deduplicated local names only; superglobals are excluded since they are sources, not laundered locals.
        return array_keys($names);
    }

    /**
     * Collects the identities of every ancestor between a node and its scope boundary.
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
     * Reports whether a finding can still reach the report without this earlier write running.
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
     * @return bool - true when the user-facing finding must keep earlier taint evidence alive
     */
    public static function isSkippableBeforeSink(Node $node, Node $sink, ?Node $scopeBoundary, array $sinkAncestorIds): bool
    {
        $parent = $node->getAttribute('parent');

        // Walk outward from the write until the sink's scope boundary is reached.
        while ($parent instanceof Node && $parent !== $scopeBoundary) {
            // A skipping ancestor means the CLI must not let this write erase a possible finding.
            if (self::isPotentialSkippingConstruct($parent)) {
                $parentId = spl_object_id($parent);

                // If the sink is outside this branch, users can reach the sink without this write.
                if (!isset($sinkAncestorIds[$parentId])) {
                    return true;
                }

                // Sibling if/else paths also keep earlier taint visible in the report.
                if ($parent instanceof Stmt\If_ && self::hasDifferentIfBranches($node, $sink, $parent)) {
                    return true;
                }
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Reports whether a node can skip code that would affect a user-facing finding.
     *
     * @param Node $node - Candidate ancestor node between a write and sink.
     *
     * @return bool - true when this ancestor can make the write optional before the sink
     */
    private static function isPotentialSkippingConstruct(Node $node): bool
    {
        return $node instanceof Stmt\If_
            || $node instanceof Stmt\ElseIf_
            || $node instanceof Stmt\Else_
            || $node instanceof Stmt\Switch_
            || $node instanceof Stmt\Case_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\Catch_
            || $node instanceof Expr\Ternary
            || $node instanceof Expr\Match_
            || $node instanceof Expr\BinaryOp\BooleanAnd
            || $node instanceof Expr\BinaryOp\BooleanOr
            || $node instanceof Expr\BinaryOp\Coalesce;
    }

    /**
     * Reports whether two nodes sit in different branches of one if-chain.
     *
     * @param Node     $node - Write/event node being classified.
     * @param Node     $sink - Sink node whose path is being compared.
     * @param Stmt\If_ $ifStatement - Shared if-chain ancestor.
     *
     * @return bool - true when the CLI should keep earlier taint because only one branch runs
     */
    private static function hasDifferentIfBranches(Node $node, Node $sink, Stmt\If_ $ifStatement): bool
    {
        $nodeBranch = self::ifBranchKey($node, $ifStatement);
        $sinkBranch = self::ifBranchKey($sink, $ifStatement);

        return $nodeBranch !== null && $sinkBranch !== null && $nodeBranch !== $sinkBranch;
    }

    /**
     * Identifies which if-chain body a node belongs to for reporting decisions.
     *
     * Conditions return null because they run before the body the user can reach.
     *
     * @param Node     $node - Descendant candidate.
     * @param Stmt\If_ $ifStatement - If-chain ancestor to classify against.
     *
     * @return string|null - stable branch key for if/elseif/else bodies, or null for conditions/unrelated nodes
     */
    private static function ifBranchKey(Node $node, Stmt\If_ $ifStatement): ?string
    {
        $current = $node;
        $parent  = $current->getAttribute('parent');

        // Climb toward the shared if-chain, then classify which branch body this node sits in.
        while ($parent instanceof Node) {
            // Once the shared if-chain is reached, classify the direct child branch.
            if ($parent === $ifStatement) {
                // The main if body is one possible path to the user-facing sink.
                if (in_array($current, $ifStatement->stmts, true)) {
                    return 'if';
                }

                // Each elseif body is a separate path in the report's runtime story.
                foreach ($ifStatement->elseifs as $elseIf) {
                    // A matching elseif node means the write and sink may be mutually exclusive.
                    if ($current === $elseIf) {
                        return 'elseif:' . spl_object_id($elseIf);
                    }
                }

                // The else body is the fallback path a user can reach when earlier tests fail.
                if ($ifStatement->else instanceof Stmt\Else_ && $current === $ifStatement->else) {
                    return 'else:' . spl_object_id($ifStatement->else);
                }

                // Conditions and unrelated children are not branch bodies, so they cannot split the finding.
                return null;
            }

            $current = $parent;
            $parent  = $current->getAttribute('parent');
        }

        return null;
    }

    /**
     * Returns the function, method, or closure scope containing a node.
     *
     * @param Node $node - Node whose containing function-like scope is needed.
     *
     * @return FunctionLike|null - nearest enclosing function/method/closure, or null when the node lives at file scope
     */
    public static function enclosingFunctionLike(Node $node): ?FunctionLike
    {
        // Climb the parent chain to the nearest function-like owner.
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
     * Reports whether a node tree builds a string via concatenation or interpolation.
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
     * Reports whether a node is a literal string, for security-rule exemptions.
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
     * Builds the display name used when reporting a function call.
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
     * Resolves a method name to its lower-case string when statically known.
     *
     * @param Expr\MethodCall|Expr\StaticCall $call - Call node to inspect.
     *
     * @return string|null - lower-cased method name, or null when the name is computed (e.g. $obj->$method())
     */
    public static function methodName(Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        // A computed method name (e.g. $obj->$method()) has no static string to resolve.
        if (!$call->name instanceof Identifier) {
            return null;
        }

        return strtolower($call->name->toString());
    }

    /**
     * Resolves a class node to a lower-case class name when statically known.
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
     * Reports whether a class node matches any exact FQCN or short class name.
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

        // Weigh the resolved name against each configured target.
        foreach ($classNames as $className) {
            $normalized = strtolower(ltrim($className, '\\'));
            // An exact match on the normalised name is a direct hit.
            if ($resolvedName === $normalized) {
                return true;
            }

            // A short target also matches when it is the final segment of the resolved FQCN.
            if (!str_contains($normalized, '\\') && str_ends_with($resolvedName, '\\' . $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a node tree contains an HTTP(S) literal.
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
     * Reports whether an expression references likely sensitive data.
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
     * Reports whether a call is an env reader requesting a sensitive key.
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

        $firstArg = self::sinkArgumentValue($call, 0);
        if ($firstArg === null) {
            // A no-argument getenv() returns the whole environment, which is treated as sensitive wholesale.
            return true;
        }

        // A literal key argument is sensitive only when its text matches the secret-name pattern.
        return $firstArg instanceof Scalar\String_ && self::hasSensitiveContext($firstArg->value);
    }

    /**
     * Reports whether text contains a secret-like word (identifier or string key).
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
