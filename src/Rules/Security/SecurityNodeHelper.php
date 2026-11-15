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
 * Shared AST helpers for security rules: request-input tracking, argument lookup and class-name matching.
 * Same-scope taint tracking follows local assignments before an inspected expression.
 * Branch helpers classify whether an earlier write can be skipped before a sink.
 */
final class SecurityNodeHelper
{
    /**
     * Parameter-name aliases for the leading global-function arguments inspected by security rules.
     * Named arguments are matched before positional arguments; unmapped slots retain positional lookup.
     * A slot can accept alternative spellings, such as sqlsrv_query's sql and tsql.
     *
     * @var array<string, list<list<string>>> - Function names mapped to ordered lists of accepted parameter names.
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
     * Identifies assignment shapes supported by local taint tracking.
     * Concat appends can taint a value; reference aliases are excluded because later writes can flow through either name.
     * Arithmetic assignments are outside this model.
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
     * @return string|null - Written local name, or null for property, offset or dynamic targets.
     */
    public static function assignmentTargetName(Expr\Assign|Expr\AssignOp\Concat $assignment): ?string
    {
        return $assignment->var instanceof Expr\Variable && is_string($assignment->var->name)
            ? $assignment->var->name
            : null;
    }

    /**
     * Returns the lower-case name from a statically named call whose syntax has one name part.
     *
     * @param FuncCall $call - Call checked for a static function name with one name part.
     *
     * @return string|null - Lower-cased one-part function name, or null for dynamic or multi-part calls.
     */
    public static function globalFunctionName(FuncCall $call): ?string
    {
        if (!$call->name instanceof Name) {
            return null;
        }

        $parts = $call->name->getParts();
        if (count($parts) !== 1) {
            // Multi-part calls do not match the built-in names modeled by these rules.
            return null;
        }

        // Lower-case because PHP function names are case-insensitive; callers compare against lower-case literals.
        return strtolower($parts[0]);
    }

    /**
     * Returns one call argument by its accepted parameter names or positional index.
     *
     * @param array<int|string, Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $args           - Call arguments; empty omits all parameters.
     * @param int                                                                      $index          - Zero-based position among unnamed arguments.
     * @param list<string>                                                             $parameterNames - Accepted names; empty uses positions only.
     *
     * @return Expr|null - Argument value, or null when the parameter is absent or represented by a placeholder.
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
     * Returns a function argument using parameter names mapped for a statically named one-part callee before positional lookup.
     * Unmapped names, slots, and dynamic or multi-part callees use positional lookup.
     *
     * @param FuncCall $call - Function call whose argument is read from its mapped name or positional slot.
     * @param int $index - Zero-based parameter position the rule wants.
     *
     * @return Expr|null - Argument value, or null when absent or represented only by a placeholder.
     */
    public static function sinkArgumentValue(FuncCall $call, int $index): ?Expr
    {
        $functionName = self::globalFunctionName($call);

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
     * @param Node $node - Expression checked for the supported disabled-flag literals.
     *
     * @return bool - true when the node is the literal false or integer 0 (the disabled-flag values rules look for)
     */
    public static function isFalseLike(Node $node): bool
    {
        if ($node instanceof Expr\ConstFetch) {

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
     * Detects request-superglobal references and names tracked as tainted by earlier same-scope assignments.
     *
     * @param Node $node - Expression searched for direct or same-scope request taint.
     *
     * @return bool - True when the tree contains a request-superglobal reference or a tracked tainted variable; false otherwise.
     */
    public static function containsUserInput(Node $node): bool
    {
        if (self::containsDirectUserInput($node)) {

            return true;
        }


        return self::containsTaintedLocal($node);
    }

    /**
     * Reports whether a node tree contains a request-superglobal reference.
     *
     * @param Node $node - Expression subtree searched for request-superglobal references.
     *
     * @return bool - True when a request superglobal is found; indirect local assignments are not followed.
     */
    private static function containsDirectUserInput(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {

                return $candidate instanceof Expr\Variable
                       && is_string($candidate->name)
                       && in_array($candidate->name, self::userInputSuperglobals(), true);
            }) instanceof Node;
    }

    /**
     * Reports whether a node reads a local assigned request data earlier in the same function-like scope.
     *
     * @param Node $node - Sink expression checked for references to earlier-tainted locals.
     *
     * @return bool - True when an enclosing function-like scope has an earlier request-tainted assignment to a local the node reads.
     */
    private static function containsTaintedLocal(Node $node): bool
    {
        $referencedVariables = self::referencedVariableNames($node);
        if ($referencedVariables === []) {

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
            // Without a byte offset, assignment order is unknown and this check reports no local taint.
            return false;
        }

        $taintedVariables = self::taintedVariableNamesBefore(array_values($statements), $scope, $sinkPosition);


        foreach ($referencedVariables as $variableName) {
            if (isset($taintedVariables[$variableName])) {

                return true;
            }
        }


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
     * @param Node                $node - Expression tree searched for names in the current taint set.
     * @param array<string, true> $taintedVariables - Tainted local-variable names known at the current sink or assignment.
     *
     * @return bool - true when the tree contains any statically named variable present in $taintedVariables
     */
    private static function hasTaintedVariableReference(Node $node, array $taintedVariables): bool
    {

        foreach (self::referencedVariableNames($node) as $variableName) {
            if (isset($taintedVariables[$variableName])) {

                return true;
            }
        }


        return false;
    }

    /**
     * Returns distinct statically named variables, excluding the configured request-input superglobals.
     *
     * @param Node $node - Expression tree searched for statically named local variables.
     *
     * @return list<string> - Distinct eligible names in traversal order; empty when none are found.
     */
    private static function referencedVariableNames(Node $node): array
    {
        $names      = [];
        $nodeFinder = new NodeFinder();
        $variables  = $nodeFinder->find($node, static fn(Node $candidate): bool => $candidate instanceof Expr\Variable);


        foreach ($variables as $variable) {
            // Guard the finder's loose node type before reading the name.
            if (!$variable instanceof Expr\Variable) {
                continue;
            }


            if (is_string($variable->name) && !in_array($variable->name, self::userInputSuperglobals(), true)) {
                $names[$variable->name] = true;
            }
        }


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

        while ($parent instanceof Node && $parent !== $scopeBoundary) {
            $ancestorIds[spl_object_id($parent)] = true;
            $parent                              = $parent->getAttribute('parent');
        }

        return $ancestorIds;
    }

    /**
     * Reports whether the structural model treats an earlier write as optional on a path to the sink.
     * Optional writes may add taint evidence but cannot erase evidence established before them.
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

        while ($parent instanceof Node && $parent !== $scopeBoundary) {
            // A potentially skipping ancestor means the CLI must keep earlier taint evidence for a possible finding.
            if (self::isPotentialSkippingConstruct($parent)) {
                $parentId = spl_object_id($parent);

                // A branch not shared by the sink makes the write optional in this structural model.
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
     * Reports whether the structural model classifies a node as potentially skipping code relevant to a finding.
     *
     * @param Node $node - Candidate ancestor node between a write and sink.
     *
     * @return bool - true when the model treats this ancestor as making the write optional before the sink
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
     * Conditions and unrelated descendants return null because they do not identify a branch body.
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

        while ($parent instanceof Node) {
            if ($parent === $ifStatement) {
                // Distinct keys let the caller recognise mutually exclusive branch bodies.
                if (in_array($current, $ifStatement->stmts, true)) {
                    return 'if';
                }

                foreach ($ifStatement->elseifs as $elseIf) {
                    if ($current === $elseIf) {
                        return 'elseif:' . spl_object_id($elseIf);
                    }
                }

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
     * Finds the nearest function-like node in the parent chain, including the starting node itself.
     *
     * @param Node $node - Starting node whose own type and parent chain are inspected.
     *
     * @return FunctionLike|null - Nearest function-like node, or null when the chain contains none.
     */
    public static function enclosingFunctionLike(Node $node): ?FunctionLike
    {

        $current = $node;
        while ($current instanceof Node) {
            if ($current instanceof FunctionLike) {

                return $current;
            }

            $parent  = $current->getAttribute('parent');
            $current = $parent instanceof Node ? $parent : null;
        }


        return null;
    }

    /**
     * Reports whether a node tree builds a string via concatenation or interpolation.
     *
     * @param Node $node - Node tree to inspect.
     *
     * @return bool - true when the tree builds a string with the period operator or interpolation, which may splice in untrusted data
     */
    public static function containsConcatOrInterpolation(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {

                return $candidate instanceof Expr\BinaryOp\Concat
                       || $candidate instanceof Scalar\Encapsed;
            }) instanceof Node;
    }

    /**
     * Lists the outermost concatenations and interpolated strings under a root, skipping any inside an opaque operand.
     *
     * @param Node                 $root     - Expression whose string constructions are wanted.
     * @param callable(Node): bool $isOpaque - Identifies an operand whose descendants are excluded because it escapes its complete argument.
     *
     * @return list<Node> - Constructions whose parent is not itself a construction and that sit inside no opaque operand.
     */
    public static function outermostStringConstructions(Node $root, callable $isOpaque): array
    {
        return array_values((new NodeFinder())->find($root, static function (Node $candidate) use ($root, $isOpaque): bool {
            if (!self::isStringConstruction($candidate) || self::isStringConstruction($candidate->getAttribute('parent'))) {
                return false;
            }
            $node = $candidate;
            // An escaper handles its argument as one value, so nested constructions are excluded from the root's inspection.
            while ($node !== $root && ($node = $node->getAttribute('parent')) instanceof Node) {
                if ($isOpaque($node)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Flattens concatenations in source order and expands interpolated strings into their immediate parts.
     * Other nodes are returned as a single operand.
     *
     * @param Node $construction - Node whose string-construction operands are needed.
     *
     * @return list<Node> - Ordered operands; empty for an interpolated string with no parts.
     */
    public static function flattenedOperands(Node $construction): array
    {
        if ($construction instanceof Expr\BinaryOp\Concat) {
            return [...self::flattenedOperands($construction->left), ...self::flattenedOperands($construction->right)];
        }

        return $construction instanceof Scalar\InterpolatedString ? array_values($construction->parts) : [$construction];
    }

    /**
     * Reports whether a call sits between a node and its root expression, so the call can splice other values around it.
     *
     * @param Node $node - Node under the root.
     * @param Node $root - Sink argument the walk stops at, itself included.
     *
     * @return bool - True when a function, method, static or constructor call is the node's ancestor up to the root.
     */
    public static function hasCallBetween(Node $node, Node $root): bool
    {
        while ($node !== $root && ($node = $node->getAttribute('parent')) instanceof Node) {
            if ($node instanceof Expr\CallLike) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether this heuristic finds an unmatched tracked quote before the next spliced value.
     *
     * @param string $text                        - Literal text spliced before the value.
     * @param string $quotes                      - Quote characters to track, such as single and double quotes.
     * @param bool   $isBackslashEscapeInSingle   - Whether this heuristic skips a backslash and its next character inside single quotes.
     *
     * @return bool - True when one of the quotes is still open at the end of the text.
     */
    public static function hasOpenQuote(string $text, string $quotes, bool $isBackslashEscapeInSingle): bool
    {
        $open   = null;
        $length = strlen($text);
        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];
            // Skip the next character after a backslash unless the heuristic treats it as literal inside single quotes.
            if ($character === '\\' && ($open !== "'" || $isBackslashEscapeInSingle)) {
                $index++;
                continue;
            }
            if (str_contains($quotes, $character) && ($open === null || $open === $character)) {
                $open = $open === null ? $character : null;
            }
        }

        return $open !== null;
    }

    /**
     * Reports whether a node is a concatenation or an interpolated string.
     *
     * @param mixed $node - Candidate node or parent attribute.
     *
     * @return bool - True for concatenations or interpolated strings, regardless of their quote syntax.
     */
    private static function isStringConstruction(mixed $node): bool
    {
        return $node instanceof Expr\BinaryOp\Concat || $node instanceof Scalar\InterpolatedString;
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

            return null;
        }

        $resolvedName = $class->getAttribute('resolvedName');
        if ($resolvedName instanceof Name) {
            // Prefer the import/namespace-resolved FQCN so short aliases match their fully-qualified target.
            return strtolower($resolvedName->toString());
        }


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
        // Match normalized names or short-target namespace tails; user-defined classes can share a built-in name.


        $resolvedName = self::className($class);
        if ($resolvedName === null) {

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

                    return false;
                }

                // Match literal outbound URL strings for URL-only sinks.
                return preg_match('/^https?:\/\//i', $candidate->value) === 1;
            }) instanceof Node;
    }
}
