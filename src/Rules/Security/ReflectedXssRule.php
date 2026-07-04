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
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Flags request-derived data that reaches output without HTML escaping (reflected XSS).
 *
 * The rule treats `echo`, `print`, `printf`, and `vprintf` as output sinks and
 * uses an escaping-aware, same-function request-taint walk as the source. A
 * request value is considered safe once it passes through an HTML escaper
 * (`htmlspecialchars`, `htmlentities`, `e`), a URL encoder, or a numeric
 * cast - so escaped output never fires. Taint follows simple local assignments
 * within one function/file scope only; whole-program flow is out of scope.
 */
final class ReflectedXssRule implements RuleInterface
{
    /** Stable rule identifier for reflected-XSS findings. */
    public const ID = 'security.reflected-xss';

    /**
     * Functions whose output of request data is considered escaped/encoded.
     *
     * @var list<string>
     */
    private const ESCAPER_FUNCTIONS = ['htmlspecialchars', 'htmlentities', 'e', 'urlencode', 'rawurlencode', 'intval', 'floatval', 'boolval'];

    /**
     * Output functions that render their arguments to the response body.
     *
     * @var list<string>
     */
    private const PRINTF_SINKS = ['printf', 'vprintf'];

    /**
     * Describes the reflected-XSS rule for the registry and reports.
     *
     * @return RuleDefinition - warning-severity, medium-confidence security metadata plus the escaping false-positive shape
     */
    public function definition(): RuleDefinition
    {
        // The rule reports warning-level sinks because unescaped request output is exploitable.
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
                                         'shape'      => 'Request value escaped or cast before output (htmlspecialchars/e()/(int)).',
                                         'mitigation' => 'The rule treats escaper/encoder/numeric-cast wrappers as safe; wrap the value at the output site.',
                                     ],
                                 ],
        );
    }

    /**
     * Reports request-derived output that is not HTML-escaped.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per output sink reached by unescaped request data; empty when the unit is clean
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every echo statement, the most common output sink.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Echo_::class) as $echo) {
            // An echo can render several comma-separated values, so check each one.
            foreach ($echo->exprs as $expr) {
                // One unescaped request value in the list is enough to flag the echo.
                if ($this->hasUnescapedRequestSource($expr, $analysisUnit)) {
                    $findings[] = $this->finding($analysisUnit, $echo->getStartLine(), 'echo');
                    break;
                }
            }
        }

        // Weigh every print expression, which renders a single value.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Print_::class) as $print) {
            // Flag the print when its value carries unescaped request data.
            if ($this->hasUnescapedRequestSource($print->expr, $analysisUnit)) {
                $findings[] = $this->finding($analysisUnit, $print->getStartLine(), 'print');
            }
        }

        // Weigh every function call for a printf-family output sink.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // Only printf and vprintf render their arguments to the response.
            if ($name === null || !in_array($name, self::PRINTF_SINKS, true)) {
                continue;
            }

            // Any format or argument slot can carry the tainted value.
            foreach ($call->args as $arg) {
                // One unescaped request argument means the printf output is exploitable.
                if ($arg instanceof Node\Arg && $this->hasUnescapedRequestSource($arg->value, $analysisUnit)) {
                    $findings[] = $this->finding($analysisUnit, $call->getStartLine(), $name);
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * Reports whether an output expression carries unescaped request data.
     *
     * @param Expr         $output - Expression rendered to output.
     * @param AnalysisUnit $analysisUnit - Parsed unit (top-level scope fallback).
     *
     * @return bool - true when a request source reaches the sink unescaped, whether directly or via a tainted local alias
     */
    private function hasUnescapedRequestSource(Expr $output, AnalysisUnit $analysisUnit): bool
    {
        // A request superglobal used directly at the sink is the clearest XSS shape.
        foreach ($this->superglobalLeaves($output) as $leaf) {
            if (!$this->isEscapedBetween($leaf, $output)) {
                // A raw request superglobal at the sink is enough to flag the output.
                return true;
            }
        }

        $scope      = $this->enclosingFunctionLike($output);
        $statements = $scope instanceof FunctionLike ? ($scope->getStmts() ?? []) : $analysisUnit->statements;
        $tainted    = $this->unescapedTaintedLocals(array_values($statements), $scope, $output->getStartFilePos());
        if ($tainted === []) {
            // No tainted aliases exist in scope, so variable leaves cannot carry request data.
            return false;
        }

        // A local alias can smuggle request data to the sink even when no superglobal appears inline.
        foreach ($this->variableLeaves($output) as $variable) {
            if (is_string($variable->name) && isset($tainted[$variable->name]) && !$this->isEscapedBetween($variable, $output)) {
                // A tainted alias reaching the sink remains unsafe unless wrapped by an escaper.
                return true;
            }
        }

        // No request source reaches the output expression unescaped.
        return false;
    }

    /**
     * Computes the local variables holding unescaped request data before the sink.
     *
     * @param list<Stmt>        $statements - Statements of the owning scope.
     * @param FunctionLike|null $scope - Owning function-like scope, or null for top level.
     * @param int               $sinkPosition - Byte offset of the output expression.
     *
     * @return array<string, true> - variable names still tainted immediately before the sink, keyed for set-membership lookup
     */
    private function unescapedTaintedLocals(array $statements, ?FunctionLike $scope, int $sinkPosition): array
    {
        if ($sinkPosition < 0) {
            // Parser positions can be unavailable; without ordering proof, no local alias is trusted.
            return [];
        }

        $tainted     = [];
        $nodeFinder  = new NodeFinder();
        $assignments = $nodeFinder->find(
            $statements,
            static fn(Node $candidate): bool => SecurityNodeHelper::isTaintTrackedAssignment($candidate)
                                                && $candidate->getStartFilePos() >= 0
                                                && $candidate->getStartFilePos() < $sinkPosition,
        );
        usort(
            $assignments,
            static fn(Node $left, Node $right): int => $left->getStartFilePos() <=> $right->getStartFilePos(),
        );

        // Replay each write in source order so the alias map at the echo/print reflects what really ran.
        foreach ($assignments as $assignment) {
            // Narrow to the tracked assignment shapes; the finder predicate already matched them.
            if (!($assignment instanceof Expr\Assign) && !($assignment instanceof Expr\AssignOp\Concat)) {
                continue;
            }

            $variableName = SecurityNodeHelper::assignmentTargetName($assignment);
            // Property and array-offset targets are beyond same-scope alias tracking; skip them.
            if ($variableName === null) {
                continue;
            }

            // Writes inside nested closures cannot affect this scope's aliases at the sink.
            if ($this->enclosingFunctionLike($assignment) !== $scope) {
                continue;
            }

            // Unescaped request data on the right side marks the target as a dangerous alias.
            if ($this->hasUnescapedRequestExpression($assignment->expr, $tainted)) {
                $tainted[$variableName] = true;
                continue;
            }

            // A clean concat append neither taints nor cleans: the alias keeps whatever it held.
            if ($assignment instanceof Expr\AssignOp\Concat) {
                continue;
            }

            unset($tainted[$variableName]);
        }

        // The map contains only aliases still tainted immediately before the sink.
        return $tainted;
    }

    /**
     * Reports whether an assignment right-hand side carries unescaped request data.
     *
     * @param Expr                $expr - Right-hand side expression.
     * @param array<string, true> $tainted - Already-tainted local variable names.
     *
     * @return bool - true when the right-hand side reads a request superglobal or a still-tainted alias without an escaper
     */
    private function hasUnescapedRequestExpression(Expr $expr, array $tainted): bool
    {
        // A request superglobal on the right-hand side taints the assigned local directly.
        foreach ($this->superglobalLeaves($expr) as $leaf) {
            if (!$this->isEscapedBetween($leaf, $expr)) {
                // Direct assignment from a request superglobal taints the local.
                return true;
            }
        }

        // Reading an already-tainted alias carries its request data forward.
        foreach ($this->variableLeaves($expr) as $variable) {
            if (is_string($variable->name) && isset($tainted[$variable->name]) && !$this->isEscapedBetween($variable, $expr)) {
                // Taint propagates through aliases until an escaping wrapper is encountered.
                return true;
            }
        }

        // The assignment expression does not carry an unescaped request source.
        return false;
    }

    /**
     * Reports whether a leaf is wrapped by an escaper/encoder/cast up to a root.
     *
     * @param Node $leaf - Request-source leaf node.
     * @param Node $root - Output expression boundary (inclusive).
     *
     * @return bool - true when an escaper/encoder call or numeric cast encloses the leaf on the path up to the root
     */
    private function isEscapedBetween(Node $leaf, Node $root): bool
    {
        // Walk outward from the request leaf toward the output boundary.
        $current = $leaf;
        while ($current instanceof Node) {
            if ($current !== $leaf && ($this->isEscaperCall($current) || $this->isNumericCast($current))) {
                // An enclosing escaper/cast neutralises the request value before the sink.
                return true;
            }

            // Stop once the walk reaches the output expression itself.
            if ($current === $root) {
                break;
            }

            $parent  = $current->getAttribute('parent');
            $current = $parent instanceof Node ? $parent : null;
        }

        // No neutralising wrapper was found between the leaf and the output root.
        return false;
    }

    /**
     * Reports whether a node is an escaper/encoder function call.
     *
     * @param Node $node - Candidate node.
     *
     * @return bool - true when the node is a global call to one of the recognised escaper/encoder functions
     */
    private function isEscaperCall(Node $node): bool
    {
        if (!$node instanceof Expr\FuncCall) {
            // Non-call nodes cannot be one of the recognised escaper functions.
            return false;
        }

        $name = SecurityNodeHelper::globalFunctionName($node);

        // Only known global escaper/encoder functions are trusted as sanitising boundaries.
        return $name !== null && in_array($name, self::ESCAPER_FUNCTIONS, true);
    }

    /**
     * Reports whether a node is a numeric/boolean cast that neutralises markup.
     *
     * @param Node $node - Candidate node.
     *
     * @return bool - true for an int, float, or bool cast, which strips markup before it can reach an HTML sink
     */
    private function isNumericCast(Node $node): bool
    {
        // Numeric/boolean casts prevent markup from surviving into an HTML sink.
        return $node instanceof Expr\Cast\Int_
               || $node instanceof Expr\Cast\Double
               || $node instanceof Expr\Cast\Bool_;
    }

    /**
     * Lists the request-superglobal variable nodes within an expression.
     *
     * @param Node $node - Expression to inspect.
     *
     * @return list<Expr\Variable> - request-superglobal variable leaves found beneath the node; empty when none are request sources
     */
    private function superglobalLeaves(Node $node): array
    {
        $leaves = [];
        // Keep only the variable leaves that name a request superglobal.
        foreach ($this->variableLeaves($node) as $variable) {
            // A superglobal-named leaf is a request source we must track.
            if (is_string($variable->name) && in_array($variable->name, SecurityNodeHelper::userInputSuperglobals(), true)) {
                $leaves[] = $variable;
            }
        }

        // Only request superglobal variable leaves are sources.
        return $leaves;
    }

    /**
     * Lists the variable nodes within an expression.
     *
     * @param Node $node - Expression to inspect.
     *
     * @return list<Expr\Variable> - every variable leaf in the expression subtree, in finder order; empty when the node has no variables
     */
    private function variableLeaves(Node $node): array
    {
        $variables = [];
        // Collect every variable node the finder returns beneath this expression.
        foreach ((new NodeFinder())->find($node, static fn(Node $candidate): bool => $candidate instanceof Expr\Variable) as $variable) {
            // Guard the finder's loose node type before treating it as a variable.
            if ($variable instanceof Expr\Variable) {
                $variables[] = $variable;
            }
        }

        // The finder returns every variable leaf beneath the expression tree.
        return $variables;
    }

    /**
     * Returns the function, method, or closure scope containing a node.
     *
     * @param Node $node - Node whose containing function-like scope is needed.
     *
     * @return FunctionLike|null - closest enclosing function/method/closure that bounds aliasing, or null at file top level
     */
    private function enclosingFunctionLike(Node $node): ?FunctionLike
    {
        // Climb the parent chain looking for the nearest function-like owner.
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if ($current instanceof FunctionLike) {
                // The closest function-like parent defines the aliasing scope.
                return $current;
            }

            $parent  = $current->getAttribute('parent');
            $current = $parent instanceof Node ? $parent : null;
        }

        // Top-level code has no function-like owner.
        return null;
    }

    /**
     * Builds a reflected-XSS finding for an output sink.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit being analysed.
     * @param int          $line - Sink line number.
     * @param string       $sink - Output sink name (echo/print/printf/vprintf).
     *
     * @return Finding - warning-level finding naming only the sink, with remediation; never includes the tainted value
     */
    private function finding(AnalysisUnit $analysisUnit, int $line, string $sink): Finding
    {
        // Metadata names only the sink, never the tainted request value.
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
