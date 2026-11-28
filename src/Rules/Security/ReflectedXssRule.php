<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

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
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Detects unescaped request data reaching `echo`, `print`, `printf`, and `vprintf`, tracing same-scope local assignments.
 *
 * Accepts recognized escapers, encoders, numeric casts, probes, and formatters; JSON encoding requires sibling headers to stay JSON.
 * Excludes output after a recognized top-level CLI guard when no executable code precedes the guard.
 */
final class ReflectedXssRule implements RuleInterface
{
    /** Stable rule identifier for reflected-XSS findings. */
    public const ID = 'security.reflected-xss';

    /**
     * Functions treated as safe wrappers when tracing request data toward HTML output.
     *
     * `esc_url_raw` is deliberately absent: it prepares a URL for storage and does not encode it for HTML.
     *
     * @var list<string> - Global function names accepted as safe wrappers.
     */
    private const ESCAPER_FUNCTIONS = [
        'htmlspecialchars',
        'htmlentities',
        'e',
        'esc_html',
        'esc_attr',
        'esc_url',
        'urlencode',
        'rawurlencode',
        'intval',
        'floatval',
        'boolval',
    ];

    /**
     * Output functions that print their arguments into the response body.
     *
     * @var list<string> - Global function names inspected as formatted-output sinks.
     */
    private const PRINTF_SINKS = ['printf', 'vprintf'];

    /**
     * Number formatters whose numeric input is treated as safe for HTML output.
     *
     * @var list<string> - Function names whose first positional argument is accepted as numeric input.
     */
    private const NUMERIC_FORMATTERS = ['absint', 'number_format', 'number_format_i18n'];

    /**
     * Describes the rule for `list-rules` and for each finding it reports.
     *
     * @return RuleDefinition - warning-severity, medium-confidence security metadata plus the escaping false-positive shape
     */
    public function definition(): RuleDefinition
    {
        // Warning-level because unescaped request output can run an attacker's script in a visitor's browser.
        return new RuleDefinition(
            id:                  self::ID,
            name:                'Reflected XSS sink',
            pillar:              Pillar::Security,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Warning,
            confidence:          Confidence::Medium,
            description:         'Request-derived data echoed/printed without HTML escaping (reflected XSS).',
            falsePositiveShapes: [
                                     [
                                         'shape'      => 'Request value escaped or cast before output (htmlspecialchars, e(), WordPress esc_html/esc_attr/esc_url, or (int)).',
                                         'mitigation' => 'The rule treats escaper/encoder/numeric-cast wrappers as safe; wrap the value at the output site.',
                                     ],
                                 ],
        );
    }

    /**
     * Checks echo, print, printf and vprintf for unescaped request input.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per matched output node; empty when no unescaped request source is detected.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings         = [];
        $commandLineGuard = $this->commandLineGuard($analysisUnit);


        // Inspect every echo statement in the file for unescaped request data.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Echo_::class) as $echo) {
            // Output classified as command-line-only is outside this page-oriented XSS rule.
            if ($this->runsOnlyFromCommandLine($echo, $commandLineGuard)) {
                continue;
            }

            $isJsonResponse = $this->answersWithJson($echo, $analysisUnit);

            // Check each comma-separated expression passed to echo.
            foreach ($echo->exprs as $echoedValue) {
                // One raw request value is enough to report the echo once.
                if ($this->printsRawRequestData($echoedValue, $analysisUnit, $isJsonResponse)) {
                    $findings[] = $this->xssFinding($analysisUnit, $echo->getStartLine(), 'echo');
                    break;
                }
            }
        }

        // Inspect every print expression in the file for unescaped request data.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Print_::class) as $print) {
            $isJsonResponse = $this->answersWithJson($this->statementHolding($print), $analysisUnit);

            // Flag print when it is reachable from web requests and outputs raw request data.
            if (!$this->runsOnlyFromCommandLine($print, $commandLineGuard) && $this->printsRawRequestData($print->expr, $analysisUnit, $isJsonResponse)) {
                $findings[] = $this->xssFinding($analysisUnit, $print->getStartLine(), 'print');
            }
        }

        // Inspect global printf() and vprintf() calls that write formatted output directly to the response body.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $functionName = SecurityNodeHelper::globalFunctionName($call);

            // Skip unrelated calls and CLI-only output that cannot execute in a browser response.
            if ($functionName === null || !in_array($functionName, self::PRINTF_SINKS, true) || $this->runsOnlyFromCommandLine($call, $commandLineGuard)) {
                continue;
            }

            $isJsonResponse = $this->answersWithJson($this->statementHolding($call), $analysisUnit);

            // Check every argument because either the format string or a substituted value may carry raw request data.
            foreach ($call->args as $argument) {
                // One raw request argument is enough to report the call once.
                if ($argument instanceof Node\Arg && $this->printsRawRequestData($argument->value, $analysisUnit, $isJsonResponse)) {
                    $findings[] = $this->xssFinding($analysisUnit, $call->getStartLine(), $functionName);
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * Reports whether a printed value carries raw request data, directly or through a local variable.
     *
     * @param Expr         $printedValue   - Value being echoed or printed.
     * @param AnalysisUnit $analysisUnit   - Parsed file whose top-level statements supply assignments for output outside a function.
     * @param bool         $isJsonResponse - Whether the response is JSON, so json_encode() output counts as safe.
     *
     * @return bool - True when a direct source or tracked local lacks a recognized safe wrapper; false when none is found.
     */
    private function printsRawRequestData(Expr $printedValue, AnalysisUnit $analysisUnit, bool $isJsonResponse): bool
    {
        // First check direct request superglobals such as $_GET['name'] inside the printed expression.
        foreach ($this->requestInputLeaves($printedValue) as $requestInput) {
            // Flag immediately when any direct superglobal reaches the sink without escaping or casting.
            if (!$this->isMadeSafeBefore($requestInput, $printedValue, $isJsonResponse)) {
                return true;
            }
        }

        $owningFunction = $this->enclosingFunctionLike($printedValue);
        $statements     = $owningFunction instanceof FunctionLike ? ($owningFunction->getStmts() ?? []) : $analysisUnit->statements;
        $taintedLocals  = $this->localsHoldingRawRequestData(array_values($statements), $owningFunction, $printedValue->getStartFilePos(), $isJsonResponse);

        // Without any tainted locals in scope, no variable reference can carry indirect request data.
        if ($taintedLocals === []) {
            return false;
        }

        // A local such as `$name = $_GET['name']; echo $name;` carries request data even with no superglobal in sight.
        foreach ($this->variableLeaves($printedValue) as $variable) {
            // Flag when the output reads a tainted local without wrapping it in a recognized escaper or cast.
            if (is_string($variable->name) && isset($taintedLocals[$variable->name]) && !$this->isMadeSafeBefore($variable, $printedValue, $isJsonResponse)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replays a function's assignments up to the output and returns the locals that still hold raw request data there.
     *
     * @param list<Stmt>        $statements - Statements of the function, or of the file for top-level output.
     * @param FunctionLike|null $owningFunction - Function that holds the output; null for top-level code.
     * @param int               $outputPosition - Byte offset of the printed value.
     * @param bool              $isJsonResponse - Whether the response is JSON, so json_encode() output counts as safe.
     *
     * @return array<string, true> - names of locals holding raw request data at the output; empty when none do
     */
    private function localsHoldingRawRequestData(array $statements, ?FunctionLike $owningFunction, int $outputPosition, bool $isJsonResponse): array
    {
        // Without a source position the order of assignments is unknown, so no local is trusted as tainted.
        if ($outputPosition < 0) {
            return [];
        }

        $taintedLocals = [];
        $nodeFinder    = new NodeFinder();
        $assignments   = $nodeFinder->find(
            $statements,
            static fn(Node $candidate): bool => SecurityNodeHelper::isTaintTrackedAssignment($candidate)
                                                && $candidate->getStartFilePos() >= 0
                                                && $candidate->getStartFilePos() < $outputPosition,
        );
        usort(
            $assignments,
            static fn(Node $left, Node $right): int => $left->getStartFilePos() <=> $right->getStartFilePos(),
        );

        // Replay each write in source order, so the set reflects what the locals hold when the output runs.
        foreach ($assignments as $assignment) {
            // The finder matched only `=` and `.=`, but the type check keeps the property reads below safe.
            if (!($assignment instanceof Expr\Assign) && !($assignment instanceof Expr\AssignOp\Concat)) {
                continue;
            }

            $localName = SecurityNodeHelper::assignmentTargetName($assignment);
            // A property or array element target, such as `$this->name = ...`, is outside local tracking.
            if ($localName === null) {
                continue;
            }

            // A write inside a nested closure changes the closure's own local, not the one printed here.
            if ($this->enclosingFunctionLike($assignment) !== $owningFunction) {
                continue;
            }

            // Raw request data on the right-hand side, such as `$name = $_GET['name']`, taints the local.
            if ($this->carriesRawRequestData($assignment->expr, $taintedLocals, $isJsonResponse)) {
                $taintedLocals[$localName] = true;
                continue;
            }

            // Appending clean text with `.=` keeps whatever the local already held.
            if ($assignment instanceof Expr\AssignOp\Concat) {
                continue;
            }

            unset($taintedLocals[$localName]);
        }

        return $taintedLocals;
    }

    /**
     * Reports whether an assignment's right-hand side carries raw request data, directly or from another tainted local.
     *
     * @param Expr                $assignedValue - Right-hand side of the assignment.
     * @param array<string, true> $taintedLocals - Locals already holding raw request data; empty before the first tainting write.
     * @param bool                $isJsonResponse - Whether the response is JSON, so json_encode() output counts as safe.
     *
     * @return bool - true when a superglobal or tainted local reaches the value unescaped; false when each is made safe first
     */
    private function carriesRawRequestData(Expr $assignedValue, array $taintedLocals, bool $isJsonResponse): bool
    {
        // Check direct request superglobals on the right-hand side of the assignment first.
        foreach ($this->requestInputLeaves($assignedValue) as $requestInput) {
            // Unless an escaper or other neutraliser wraps it first.
            if (!$this->isMadeSafeBefore($requestInput, $assignedValue, $isJsonResponse)) {
                return true;
            }
        }

        // Copying a tainted local, such as `$label = 'Hi ' . $name`, carries its request data forward.
        foreach ($this->variableLeaves($assignedValue) as $variable) {
            // Unless an escaper or other neutraliser wraps it first.
            if (is_string($variable->name) && isset($taintedLocals[$variable->name]) && !$this->isMadeSafeBefore($variable, $assignedValue, $isJsonResponse)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether the rule recognizes a safe wrapper between a request value and the enclosing printed or assigned value.
     *
     * @param Node $requestValue   - Superglobal or tainted local inside the printed or assigned value.
     * @param Node $outerValue     - The printed or assigned value that bounds the climb.
     * @param bool $isJsonResponse - Whether the response is JSON, so json_encode() output counts as safe.
     *
     * @return bool - True when a recognized safe wrapper is found before or at the bound; false otherwise.
     */
    private function isMadeSafeBefore(Node $requestValue, Node $outerValue, bool $isJsonResponse): bool
    {
        $current = $requestValue;
        $child   = $requestValue;

        while ($current instanceof Node) {
            // Stop as soon as an enclosing escaper, cast, probe, or JSON encoder neutralises the request value.
            if ($current !== $requestValue && $this->makesValueSafe($current, $child, $isJsonResponse)) {
                return true;
            }

            // Do not climb past the printed or assigned root expression being evaluated.
            if ($current === $outerValue) {
                break;
            }

            $child   = $current;
            $parent  = $current->getAttribute('parent');
            $current = $parent instanceof Node ? $parent : null;
        }

        return false;
    }

    /**
     * Reports whether the rule accepts a wrapper as safe for a request value.
     * Formatters qualify only for their first positional argument; JSON encoding requires a JSON response.
     *
     * @param Node $wrapper - Ancestor of the request value.
     * @param Node $child - The node the climb came through, so a call can tell which argument holds the value.
     * @param bool $isJsonResponse - Whether the response is JSON, so json_encode() output counts as safe.
     *
     * @return bool - True for a listed escaper, int/float/bool cast, isset/empty or qualifying formatter/JSON call; false otherwise.
     */
    private function makesValueSafe(Node $wrapper, Node $child, bool $isJsonResponse): bool
    {
        // An escaper or cast rewrites the value, and isset() or empty() turns it into a boolean.
        if ($this->isHtmlEscaperCall($wrapper) || $this->isNumericCast($wrapper) || $wrapper instanceof Expr\Isset_ || $wrapper instanceof Expr\Empty_) {
            return true;
        }

        $functionName = $wrapper instanceof Expr\FuncCall ? SecurityNodeHelper::globalFunctionName($wrapper) : null;
        // Only the number comes out as digits; number_format()'s separator arguments are printed exactly as given.
        if ($wrapper instanceof Expr\FuncCall && in_array($functionName, self::NUMERIC_FORMATTERS, true)) {
            return $child instanceof Node\Arg && $child->name === null && ($wrapper->args[0] ?? null) === $child;
        }

        // JSON text holds no markup a browser runs only when the response says it is JSON.
        return $isJsonResponse && $functionName === 'json_encode';
    }

    /**
     * Returns the statement that holds an output expression, such as the `$n = printf(...);` around a printf() call.
     *
     * @param Node $outputNode - A print expression or printf() call.
     *
     * @return Stmt|null - the nearest statement ancestor; null when the node has none
     */
    private function statementHolding(Node $outputNode): ?Stmt
    {
        $ancestor = $outputNode->getAttribute('parent');

        while ($ancestor instanceof Node && !$ancestor instanceof Stmt) {
            $ancestor = $ancestor->getAttribute('parent');
        }

        return $ancestor instanceof Stmt ? $ancestor : null;
    }

    /**
     * Reports whether preceding sibling statements support the rule's conservative JSON-response classification.
     *
     * @param Stmt|null    $outputStatement - Statement that holds the output; null when none was found.
     * @param AnalysisUnit $analysisUnit - Parsed file whose root statements hold output written at file scope.
     *
     * @return bool - true when a direct earlier JSON Content-Type is not followed by a header that could replace it; false otherwise
     */
    private function answersWithJson(?Stmt $outputStatement, AnalysisUnit $analysisUnit): bool
    {
        $holder          = $outputStatement?->getAttribute('parent');
        $statementBlocks = $holder instanceof Node
            ? array_filter(array_map(static fn(string $name): mixed => $holder->{$name}, $holder->getSubNodeNames()), 'is_array')
            : [$analysisUnit->statements];
        $owningFunction   = $outputStatement === null ? null : $this->enclosingFunctionLike($outputStatement);
        $isJson           = false;
        $hasOutputStarted = false;
        // Locate the sibling statement list that actually contains the output statement.
        foreach ($statementBlocks as $statementBlock) {
            // Other blocks of the same holder, such as the else branch, do not run before this output.
            if (!in_array($outputStatement, $statementBlock, true)) {
                continue;
            }

            // Walk the statements before the output in order, tracking the latest content type and whether body output already started.
            foreach ($statementBlock as $earlierStatement) {
                // This heuristic credits only headers that appear before the output statement.
                if ($earlierStatement === $outputStatement) {
                    break;
                }

                // Only a statement node can hold a header() or output call; an empty slot in the block holds nothing.
                if (!$earlierStatement instanceof Node) {
                    continue;
                }

                // Once response output has already started, later header() calls cannot switch the response into JSON.
                if (!$hasOutputStarted) {
                    $isJson = $this->updatedJsonStateForStatement($earlierStatement, $owningFunction, $isJson);
                }

                // Record when an earlier statement emits body output or flushes headers so later header() calls cannot turn JSON on.
                if ($this->statementStartsResponseOutput($earlierStatement, $owningFunction)) {
                    $hasOutputStarted = true;
                }
            }
        }

        return $isJson;
    }

    /**
     * Applies every same-function `header()` call inside one statement to the running JSON-response classification.
     *
     * @param Node              $statement      - Earlier statement inspected for `header()` calls.
     * @param FunctionLike|null $owningFunction - Function enclosing the inspected output, or null at file scope.
     * @param bool              $isJson         - Whether the response was classified as JSON before this statement.
     *
     * @return bool - Updated JSON-response classification after applying the statement's `header()` calls.
     */
    private function updatedJsonStateForStatement(Node $statement, ?FunctionLike $owningFunction, bool $isJson): bool
    {
        // Inspect every function call inside the statement to catch direct or branch-nested header() calls.
        foreach ((new NodeFinder())->findInstanceOf($statement, Expr\FuncCall::class) as $call) {
            // A header() inside a nested function runs only if that function is called.
            if (SecurityNodeHelper::globalFunctionName($call) !== 'header' || $this->enclosingFunctionLike($call) !== $owningFunction) {
                continue;
            }

            // A header() nested in a branch may not run, so it can switch JSON off but never on.
            $isJsonAfterCall = $this->isJsonAfterHeader($call, $isJson);
            $isJson          = $statement instanceof Stmt\Expression && $statement->expr === $call ? $isJsonAfterCall : $isJson && $isJsonAfterCall;
        }

        return $isJson;
    }

    /**
     * Reports whether an earlier statement in the same function can emit response body output or flush headers.
     *
     * @param Node              $statement      - Statement appearing before the inspected output.
     * @param FunctionLike|null $owningFunction - Function enclosing the inspected output, or null at file scope.
     *
     * @return bool - True when the statement directly outputs or flushes in the same function; false otherwise.
     */
    private function statementStartsResponseOutput(Node $statement, ?FunctionLike $owningFunction): bool
    {
        $outputNodes = (new NodeFinder())->find(
            [$statement],
            fn (Node $candidate): bool => $this->enclosingFunctionLike($candidate) === $owningFunction
                && (
                    $candidate instanceof Stmt\Echo_
                    || $candidate instanceof Stmt\InlineHTML
                    || $candidate instanceof Expr\Print_
                    || ($candidate instanceof Expr\FuncCall && in_array(SecurityNodeHelper::globalFunctionName($candidate), ['printf', 'vprintf', 'flush', 'ob_flush', 'readfile', 'fpassthru'], true))
                ),
        );

        return $outputNodes !== [];
    }

    /**
     * Updates the rule's JSON-response classification after one header() call.
     *
     * @param Expr\FuncCall $headerCall - A header() call before the output.
     * @param bool          $wasJson - Whether the response was JSON before this call.
     *
     * @return bool - true after a JSON Content-Type literal; false after any other Content-Type or a header the scan cannot read
     */
    private function isJsonAfterHeader(Expr\FuncCall $headerCall, bool $wasJson): bool
    {
        $headerLine = SecurityNodeHelper::sinkArgumentValue($headerCall, 0);
        // A computed header, such as header('Content-Type: ' . $type), could set any content type.
        if (!$headerLine instanceof Scalar\String_) {
            return false;
        }

        // Treat application/json and +json media-type literals, such as application/problem+json, as JSON responses.
        if (preg_match('~^\s*content-type\s*:\s*application/([\w.+-]*\+)?json\b~i', $headerLine->value) === 1) {
            return true;
        }

        // Treat another literal Content-Type as replacing JSON; a different literal header preserves the earlier classification.
        return $wasJson && preg_match('/^\s*content-type\s*:/i', $headerLine->value) !== 1;
    }

    /**
     * Finds a top-level guard that stops non-command-line SAPIs and whether any executable statement precedes it.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file whose top-level statements are searched.
     *
     * @return array{endOffset: int, hasEarlierExecutableCode: bool}|null - Guard metadata; null when no recognized guard exists.
     */
    private function commandLineGuard(AnalysisUnit $analysisUnit): ?array
    {
        $hasEarlierExecutableCode = false;

        // Only top-level guards contribute to this file-position heuristic.
        foreach ($analysisUnit->statements as $statement) {
            $isGuard = $statement instanceof Stmt\If_ && $statement->elseifs === [] && $statement->else === null
                && $this->isNotCommandLineTest($statement->cond) && $this->alwaysStopsScript($statement->stmts);
            // The first recognized guard sets the boundary used for later output nodes.
            if ($isGuard) {
                return [
                    'endOffset' => $statement->getEndFilePos(),
                    'hasEarlierExecutableCode' => $hasEarlierExecutableCode,
                ];
            }

            // Track whether executable top-level statements ran before the guard and could call hoisted functions declared later.
            if (!$this->isNonExecutableDeclaration($statement)) {
                $hasEarlierExecutableCode = true;
            }
        }

        return null;
    }

    /**
     * Reports whether a top-level statement only declares symbols or directives and executes no code before a CLI guard.
     *
     * @param Stmt $statement - Top-level statement appearing before the CLI guard.
     *
     * @return bool - True for declarations and no-ops that cannot invoke a hoisted function; false for executable statements.
     */
    private function isNonExecutableDeclaration(Stmt $statement): bool
    {
        return $statement instanceof Stmt\Declare_
            || $statement instanceof Stmt\Namespace_
            || $statement instanceof Stmt\Use_
            || $statement instanceof Stmt\GroupUse
            || $statement instanceof Stmt\Const_
            || $statement instanceof Stmt\Function_
            || $statement instanceof Stmt\ClassLike
            || $statement instanceof Stmt\Nop;
    }

    /**
     * Reports whether a condition compares the SAPI with 'cli' using != or !==.
     *
     * @param Expr $condition - Guard condition.
     *
     * @return bool - True for either operand order with PHP_SAPI or php_sapi_name(); false for other forms.
     */
    private function isNotCommandLineTest(Expr $condition): bool
    {
        // Only negative comparisons (`!=` or `!==`) express "stop when not running in CLI".
        if (!$condition instanceof Expr\BinaryOp\NotIdentical && !$condition instanceof Expr\BinaryOp\NotEqual) {
            return false;
        }

        // Accept either operand order (`PHP_SAPI !== 'cli'` or `'cli' !== PHP_SAPI`).
        foreach ([[$condition->left, $condition->right], [$condition->right, $condition->left]] as [$literal, $sapiSide]) {
            $namesSapi = SecurityNodeHelper::constantName($sapiSide) === 'PHP_SAPI'
                || ($sapiSide instanceof Expr\FuncCall && SecurityNodeHelper::globalFunctionName($sapiSide) === 'php_sapi_name');

            // Match only an explicit `'cli'` literal paired with PHP_SAPI or php_sapi_name().
            if ($namesSapi && $literal instanceof Scalar\String_ && $literal->value === 'cli') {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a guard body prevents later statements in the guarded file from running.
     *
     * @param array<Stmt> $guardBody - Statements inside the guard.
     *
     * @return bool - true when the last statement throws, exits or returns; false for an empty or non-stopping body
     */
    private function alwaysStopsScript(array $guardBody): bool
    {
        $lastStatement = end($guardBody);

        return $lastStatement instanceof Stmt\Return_
            || ($lastStatement instanceof Stmt\Expression && ($lastStatement->expr instanceof Expr\Throw_ || $lastStatement->expr instanceof Expr\Exit_));
    }

    /**
     * Reports whether the rule classifies an output as command-line-only because a top-level CLI guard dominates it.
     * Hoisted functions declared after the guard stay reportable when executable top-level code ran before the guard.
     *
     * @param Node                                                        $outputNode       - Echo, print or printf() call.
     * @param array{endOffset: int, hasEarlierExecutableCode: bool}|null $commandLineGuard - Recognized guard metadata; null when absent.
     *
     * @return bool - True when the guard dominates the output; false without a guard, before it, or in a callable reachable earlier.
     */
    private function runsOnlyFromCommandLine(Node $outputNode, ?array $commandLineGuard): bool
    {
        // Without a guard, or before the guard ends, web requests can reach this output.
        if ($commandLineGuard === null || $outputNode->getStartFilePos() <= $commandLineGuard['endOffset']) {
            return false;
        }

        // Top-level code before the guard can call a hoisted function declared below the guard, so keep function outputs reportable.
        if ($commandLineGuard['hasEarlierExecutableCode'] && $this->enclosingFunctionLike($outputNode) !== null) {
            return false;
        }

        return true;
    }

    /**
     * Reports whether a node is a call to one of the escaper or encoder functions.
     *
     * @param Node $node - Wrapper around a request value.
     *
     * @return bool - true for a global call to a listed escaper, encoder or converter; false for any other node
     */
    private function isHtmlEscaperCall(Node $node): bool
    {
        // Only a function call can invoke one of the recognized HTML/URL/scalar escaping functions.
        if (!$node instanceof Expr\FuncCall) {
            return false;
        }

        $functionName = SecurityNodeHelper::globalFunctionName($node);

        // A namespaced or computed callee is not trusted, even with a familiar name.
        return $functionName !== null && in_array($functionName, self::ESCAPER_FUNCTIONS, true);
    }

    /**
     * Reports whether a node is an int, float or bool cast, which leaves no markup to print.
     *
     * @param Node $node - Wrapper around a request value.
     *
     * @return bool - true for an int, float or bool cast; false for any other node
     */
    private function isNumericCast(Node $node): bool
    {
        return $node instanceof Expr\Cast\Int_
               || $node instanceof Expr\Cast\Double
               || $node instanceof Expr\Cast\Bool_;
    }

    /**
     * Lists the request superglobals, such as `$_GET`, inside an expression.
     *
     * @param Node $expression - Printed or assigned value to search.
     *
     * @return list<Expr\Variable> - request superglobal variables found; empty when the expression reads no request input
     */
    private function requestInputLeaves(Node $expression): array
    {
        $requestInputs = [];

        // Filter the expression's variable leaves down to PHP request superglobals (`$_GET`, `$_POST`, etc.).
        foreach ($this->variableLeaves($expression) as $variable) {
            // Keep only statically named request superglobals; ordinary local variables are checked separately.
            if (is_string($variable->name) && in_array($variable->name, SecurityNodeHelper::userInputSuperglobals(), true)) {
                $requestInputs[] = $variable;
            }
        }

        return $requestInputs;
    }

    /**
     * Lists every variable inside an expression.
     *
     * @param Node $expression - Printed or assigned value to search.
     *
     * @return list<Expr\Variable> - every variable in the expression, in finder order; empty when it has none
     */
    private function variableLeaves(Node $expression): array
    {
        $variables = [];

        // Collect every variable node referenced anywhere inside the printed or assigned expression.
        foreach ((new NodeFinder())->find($expression, static fn(Node $candidate): bool => $candidate instanceof Expr\Variable) as $variable) {
            // The finder returns loosely typed nodes, so confirm each is a variable.
            if ($variable instanceof Expr\Variable) {
                $variables[] = $variable;
            }
        }

        return $variables;
    }

    /**
     * Returns the nearest function-like scope that holds a node.
     *
     * @param Node $node - Node whose nearest function-like ancestor is requested.
     *
     * @return FunctionLike|null - Nearest enclosing function-like scope, or null for top-level code.
     */
    private function enclosingFunctionLike(Node $node): ?FunctionLike
    {
        $ancestor = $node->getAttribute('parent');

        while ($ancestor instanceof Node) {
            // The nearest function-like scope bounds which locals the output can see.
            if ($ancestor instanceof FunctionLike) {
                return $ancestor;
            }

            $parent   = $ancestor->getAttribute('parent');
            $ancestor = $parent instanceof Node ? $parent : null;
        }

        return null;
    }

    /**
     * Builds the reflected-XSS finding the user sees for one output.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file supplying the reported path.
     * @param int          $line - Line of the echo, print or printf() call.
     * @param string       $sink - Output kind: echo, print, printf or vprintf.
     *
     * @return Finding - warning-level finding naming only the output kind, never the request value itself
     */
    private function xssFinding(AnalysisUnit $analysisUnit, int $line, string $sink): Finding
    {
        // Metadata names only the output kind, so the report never repeats an attacker's payload.
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('Request-derived data reaches %s output without HTML escaping; this is a reflected XSS sink.', $sink),
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Escape request data for the output context with htmlspecialchars()/htmlentities() (or e() in Blade), or cast to int/float/bool when the value is numeric.',
            metadata:    [
                             'sink' => $sink,
                         ],
        );
    }
}
