<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
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
 * (`htmlspecialchars`, `htmlentities`, `e`), a URL/JSON encoder, or a numeric
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
    private const ESCAPER_FUNCTIONS = ['htmlspecialchars', 'htmlentities', 'e', 'urlencode', 'rawurlencode', 'json_encode', 'intval', 'floatval', 'boolval'];

    /**
     * Output functions that render their arguments to the response body.
     *
     * @var list<string>
     */
    private const PRINTF_SINKS = ['printf', 'vprintf'];

    /**
     * Describe the reflected-XSS rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // The rule reports warning-level sinks because unescaped request output is exploitable.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Reflected XSS sink',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            description:     'Request-derived data echoed/printed without HTML escaping (reflected XSS).',
            falsePositiveShapes: [
                [
                    'shape'      => 'Request value escaped or cast before output (htmlspecialchars/e()/(int)).',
                    'mitigation' => 'The rule treats escaper/encoder/numeric-cast wrappers as safe; wrap the value at the output site.',
                ],
            ],
        );
    }

    /**
     * Find request-derived output that is not HTML-escaped.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Reflected-XSS findings.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Echo_::class) as $echo) {
            foreach ($echo->exprs as $expr) {
                if ($this->hasUnescapedRequestSource($expr, $analysisUnit)) {
                    $findings[] = $this->finding($analysisUnit, $echo->getStartLine(), 'echo');
                    break;
                }
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Print_::class) as $print) {
            if ($this->hasUnescapedRequestSource($print->expr, $analysisUnit)) {
                $findings[] = $this->finding($analysisUnit, $print->getStartLine(), 'print');
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if ($name === null || !in_array($name, self::PRINTF_SINKS, true)) {
                continue;
            }

            foreach ($call->args as $arg) {
                if ($arg instanceof Node\Arg && $this->hasUnescapedRequestSource($arg->value, $analysisUnit)) {
                    $findings[] = $this->finding($analysisUnit, $call->getStartLine(), $name);
                    break;
                }
            }
        }

        // Each finding corresponds to one output sink reached by unescaped request data.
        return $findings;
    }

    /**
     * Decide whether an output expression carries unescaped request data.
     *
     * @param Expr         $output       Expression rendered to output.
     * @param AnalysisUnit $analysisUnit Parsed unit (top-level scope fallback).
     * @return bool True when a request source reaches output without escaping.
     */
    private function hasUnescapedRequestSource(Expr $output, AnalysisUnit $analysisUnit): bool
    {
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
     * Compute local variables holding unescaped request data before the sink.
     *
     * @param list<Stmt>        $statements   Statements of the owning scope.
     * @param FunctionLike|null $scope        Owning function-like scope, or null for top level.
     * @param int               $sinkPosition Byte offset of the output expression.
     * @return array<string, true> Tainted variable names carrying unescaped request data.
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
            static fn (Node $candidate): bool => $candidate instanceof Expr\Assign
                && $candidate->getStartFilePos() >= 0
                && $candidate->getStartFilePos() < $sinkPosition,
        );
        usort(
            $assignments,
            static fn (Node $left, Node $right): int => $left->getStartFilePos() <=> $right->getStartFilePos(),
        );

        foreach ($assignments as $assignment) {
            if (!$assignment instanceof Expr\Assign || !$assignment->var instanceof Expr\Variable || !is_string($assignment->var->name)) {
                continue;
            }

            if ($this->enclosingFunctionLike($assignment) !== $scope) {
                continue;
            }

            if ($this->hasUnescapedRequestExpression($assignment->expr, $tainted)) {
                $tainted[$assignment->var->name] = true;
                continue;
            }

            unset($tainted[$assignment->var->name]);
        }

        // The map contains only aliases still tainted immediately before the sink.
        return $tainted;
    }

    /**
     * Decide whether an assignment right-hand side carries unescaped request data.
     *
     * @param Expr                $expr    Right-hand side expression.
     * @param array<string, true> $tainted Already-tainted local variable names.
     * @return bool True when the expression carries unescaped request data.
     */
    private function hasUnescapedRequestExpression(Expr $expr, array $tainted): bool
    {
        foreach ($this->superglobalLeaves($expr) as $leaf) {
            if (!$this->isEscapedBetween($leaf, $expr)) {
                // Direct assignment from a request superglobal taints the local.
                return true;
            }
        }

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
     * Determine whether a leaf is wrapped by an escaper/encoder/cast up to a root.
     *
     * @param Node $leaf Request-source leaf node.
     * @param Node $root Output expression boundary (inclusive).
     * @return bool True when an escaping wrapper encloses the leaf within the root.
     */
    private function isEscapedBetween(Node $leaf, Node $root): bool
    {
        $current = $leaf;
        while ($current instanceof Node) {
            if ($current !== $leaf && ($this->isEscaperCall($current) || $this->isNumericCast($current))) {
                // An enclosing escaper/cast neutralises the request value before the sink.
                return true;
            }

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
     * Detect an escaper/encoder function call.
     *
     * @param Node $node Candidate node.
     * @return bool True when the node is an escaper/encoder call.
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
     * Detect a numeric/boolean cast that neutralises markup.
     *
     * @param Node $node Candidate node.
     * @return bool True when the node is an int/float/bool cast.
     */
    private function isNumericCast(Node $node): bool
    {
        // Numeric/boolean casts prevent markup from surviving into an HTML sink.
        return $node instanceof Expr\Cast\Int_
            || $node instanceof Expr\Cast\Double
            || $node instanceof Expr\Cast\Bool_;
    }

    /**
     * List request-superglobal variable nodes within an expression.
     *
     * @param Node $node Expression to inspect.
     * @return list<Expr\Variable>
     */
    private function superglobalLeaves(Node $node): array
    {
        $leaves = [];
        foreach ($this->variableLeaves($node) as $variable) {
            if (is_string($variable->name) && in_array($variable->name, SecurityNodeHelper::userInputSuperglobals(), true)) {
                $leaves[] = $variable;
            }
        }

        // Only request superglobal variable leaves are sources.
        return $leaves;
    }

    /**
     * List variable nodes within an expression.
     *
     * @param Node $node Expression to inspect.
     * @return list<Expr\Variable>
     */
    private function variableLeaves(Node $node): array
    {
        $variables = [];
        foreach ((new NodeFinder())->find($node, static fn (Node $candidate): bool => $candidate instanceof Expr\Variable) as $variable) {
            if ($variable instanceof Expr\Variable) {
                $variables[] = $variable;
            }
        }

        // The finder returns every variable leaf beneath the expression tree.
        return $variables;
    }

    /**
     * Find the function, method, or closure scope containing a node.
     *
     * @param Node $node Node whose containing function-like scope is needed.
     * @return FunctionLike|null Containing scope, or null at file top level.
     */
    private function enclosingFunctionLike(Node $node): ?FunctionLike
    {
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
     * Build a reflected-XSS finding for an output sink.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit being analysed.
     * @param int          $line         Sink line number.
     * @param string       $sink         Output sink name (echo/print/printf/vprintf).
     * @return Finding Reflected-XSS finding.
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
