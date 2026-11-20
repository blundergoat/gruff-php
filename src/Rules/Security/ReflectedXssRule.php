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
 * Detects request data at echo, print, printf and vprintf; traces local assignments in the same function or file scope without following calls.
 * Accepts recognized escapers, encoders, numeric casts, probes and formatters; JSON encoding requires sibling-header state to remain JSON.
 * Excludes output after a recognized top-level CLI guard.
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
        $findings            = [];
        $commandLineGuardEnd = $this->commandLineGuardEnd($analysisUnit);


        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Echo_::class) as $echo) {
            // Output classified as command-line-only is outside this page-oriented XSS rule.
            if ($this->runsOnlyFromCommandLine($echo, $commandLineGuardEnd)) {
                continue;
            }

            $isJsonResponse = $this->answersWithJson($echo, $analysisUnit);

            foreach ($echo->exprs as $echoedValue) {
                // One raw request value is enough to report the echo once.
                if ($this->printsRawRequestData($echoedValue, $analysisUnit, $isJsonResponse)) {
                    $findings[] = $this->xssFinding($analysisUnit, $echo->getStartLine(), 'echo');
                    break;
                }
            }
        }


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Print_::class) as $print) {
            $isJsonResponse = $this->answersWithJson($this->statementHolding($print), $analysisUnit);

            if (!$this->runsOnlyFromCommandLine($print, $commandLineGuardEnd) && $this->printsRawRequestData($print->expr, $analysisUnit, $isJsonResponse)) {
                $findings[] = $this->xssFinding($analysisUnit, $print->getStartLine(), 'print');
            }
        }


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $functionName = SecurityNodeHelper::globalFunctionName($call);

            if ($functionName === null || !in_array($functionName, self::PRINTF_SINKS, true) || $this->runsOnlyFromCommandLine($call, $commandLineGuardEnd)) {
                continue;
            }

            $isJsonResponse = $this->answersWithJson($this->statementHolding($call), $analysisUnit);


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
     * @param Expr         $printedValue - Value being echoed or printed.
     * @param AnalysisUnit $analysisUnit   - Parsed file whose top-level statements supply assignments for output outside a function.
     * @param bool         $isJsonResponse - Whether the response is JSON, so json_encode() output counts as safe.
     *
     * @return bool - True when a direct source or tracked local lacks a recognized safe wrapper; false when none is found.
     */
    private function printsRawRequestData(Expr $printedValue, AnalysisUnit $analysisUnit, bool $isJsonResponse): bool
    {

        foreach ($this->requestInputLeaves($printedValue) as $requestInput) {

            if (!$this->isMadeSafeBefore($requestInput, $printedValue, $isJsonResponse)) {
                return true;
            }
        }

        $owningFunction = $this->enclosingFunctionLike($printedValue);
        $statements     = $owningFunction instanceof FunctionLike ? ($owningFunction->getStmts() ?? []) : $analysisUnit->statements;
        $taintedLocals  = $this->localsHoldingRawRequestData(array_values($statements), $owningFunction, $printedValue->getStartFilePos(), $isJsonResponse);

        if ($taintedLocals === []) {
            return false;
        }

        // A local such as `$name = $_GET['name']; echo $name;` carries request data even with no superglobal in sight.
        foreach ($this->variableLeaves($printedValue) as $variable) {

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
     * @param Node $requestValue - Superglobal or tainted local inside the printed or assigned value.
     * @param Node $outerValue - The printed or assigned value that bounds the climb.
     * @param bool $isJsonResponse - Whether the response is JSON, so json_encode() output counts as safe.
     *
     * @return bool - True when a recognized safe wrapper is found before or at the bound; false otherwise.
     */
    private function isMadeSafeBefore(Node $requestValue, Node $outerValue, bool $isJsonResponse): bool
    {
        $current = $requestValue;
        $child   = $requestValue;

        while ($current instanceof Node) {

            if ($current !== $requestValue && $this->makesValueSafe($current, $child, $isJsonResponse)) {
                return true;
            }


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
        $owningFunction  = $outputStatement === null ? null : $this->enclosingFunctionLike($outputStatement);
        $isJson          = false;
        foreach ($statementBlocks as $statementBlock) {
            // Other blocks of the same holder, such as the else branch, do not run before this output.
            if (!in_array($outputStatement, $statementBlock, true)) {
                continue;
            }

            // Walk the statements before the output in order, tracking the latest content type.
            foreach ($statementBlock as $earlierStatement) {
                // This heuristic credits only headers that appear before the output statement.
                if ($earlierStatement === $outputStatement) {
                    break;
                }

                // Only a statement node can hold a header() call; an empty slot in the block holds nothing.
                if (!$earlierStatement instanceof Node) {
                    continue;
                }

                foreach ((new NodeFinder())->findInstanceOf($earlierStatement, Expr\FuncCall::class) as $call) {
                    // A header() inside a nested function runs only if that function is called.
                    if (SecurityNodeHelper::globalFunctionName($call) !== 'header' || $this->enclosingFunctionLike($call) !== $owningFunction) {
                        continue;
                    }

                    // A header() nested in a branch may not run, so it can switch JSON off but never on.
                    $isJsonAfterCall = $this->isJsonAfterHeader($call, $isJson);
                    $isJson          = $earlierStatement instanceof Stmt\Expression && $earlierStatement->expr === $call ? $isJsonAfterCall : $isJson && $isJsonAfterCall;
                }
            }
        }

        return $isJson;
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
     * Finds the end of a top-level guard the rule recognizes as excluding non-command-line SAPIs.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file whose top-level statements are searched.
     *
     * @return int|null - End byte offset of a recognized guard; null when this heuristic finds none.
     */
    private function commandLineGuardEnd(AnalysisUnit $analysisUnit): ?int
    {
        // Only top-level guards contribute to this file-position heuristic.
        foreach ($analysisUnit->statements as $statement) {
            $isGuard = $statement instanceof Stmt\If_ && $statement->elseifs === [] && $statement->else === null
                && $this->isNotCommandLineTest($statement->cond) && $this->alwaysStopsScript($statement->stmts);
            // The first recognized guard sets the boundary used for later output nodes.
            if ($isGuard) {
                return $statement->getEndFilePos();
            }
        }

        return null;
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

        if (!$condition instanceof Expr\BinaryOp\NotIdentical && !$condition instanceof Expr\BinaryOp\NotEqual) {
            return false;
        }


        foreach ([[$condition->left, $condition->right], [$condition->right, $condition->left]] as [$literal, $sapiSide]) {
            $namesSapi = SecurityNodeHelper::constantName($sapiSide) === 'PHP_SAPI'
                || ($sapiSide instanceof Expr\FuncCall && SecurityNodeHelper::globalFunctionName($sapiSide) === 'php_sapi_name');

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
     * Reports whether the rule classifies an output as command-line-only because it appears after the recognized guard.
     *
     * @param Node     $outputNode - Echo, print or printf() call.
     * @param int|null $commandLineGuardEnd - Where the guard ends; null when the file has none.
     *
     * @return bool - true when the output starts after the guard; false without a guard or before it
     */
    private function runsOnlyFromCommandLine(Node $outputNode, ?int $commandLineGuardEnd): bool
    {
        return $commandLineGuardEnd !== null && $outputNode->getStartFilePos() > $commandLineGuardEnd;
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

        foreach ($this->variableLeaves($expression) as $variable) {

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
