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
use PhpParser\NodeFinder;

/**
 * Detects archive extraction from request-controlled sources or to request-controlled destinations.
 */
final class UnsafeArchiveExtractionRule implements RuleInterface
{
    /**
     * Stable rule identifier for unsafe archive extraction.
     */
    public const ID = 'security.unsafe-archive-extraction';

    /**
     * Describe the unsafe archive extraction rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unsafe archive extraction',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find archive extraction calls with request-controlled destinations or entries.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unsafe archive extraction.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\MethodCall::class) as $call) {
            if (SecurityNodeHelper::methodName($call) !== 'extractto') {
                continue;
            }

            // Attacker-chosen destination or entry list: the original unsafe shape, reported first.
            if ($this->hasRequestControlledExtractionArgument($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
                continue;
            }

            // Destination is fixed but the archive itself came from the request (say, an upload):
            // extraction still processes attacker-supplied entries, so flag the source shape.
            if ($this->hasRequestControlledArchiveSource($analysisUnit, $call)) {
                $findings[] = $this->sourceFinding($analysisUnit, $call);
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $call) {
            if (
                SecurityNodeHelper::methodName($call) !== 'extractto'
                || !SecurityNodeHelper::hasMatchingClassName($call->class, ['PharData', 'ZipArchive'])
            ) {
                continue;
            }

            if ($this->hasRequestControlledExtractionArgument($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
            }
        }

        return $findings;
    }

    /**
     * Check whether an extractTo() call draws its destination or entry list from request input.
     *
     * @param Expr\MethodCall|Expr\StaticCall $call - extractTo() call whose first two arguments (destination, entries)
     *                                              are taint-checked against request data.
     *
     * @return bool - True when destination or selected entries come from request data.
     */
    private function hasRequestControlledExtractionArgument(Expr\MethodCall|Expr\StaticCall $call): bool
    {
        foreach ([0, 1] as $argumentIndex) {
            $argument = SecurityNodeHelper::argumentValue($call->args, $argumentIndex);
            if ($argument !== null && SecurityNodeHelper::containsUserInput($argument)) {
                // A request-tainted destination or entry list is enough to flag the extraction.
                return true;
            }
        }

        // Neither modelled argument carried request taint, so the extraction target is trusted.
        return false;
    }

    /**
     * Decide whether the extraction receiver can hold a request-controlled archive.
     *
     * Tracks the receiver variable within its own function-like scope only: an open
     * call with request-tainted input or a `new PharData($request-tainted)` assignment
     * marks it. An event the runtime could skip on the sink's path (it sits in a branch
     * the sink does not share) can add upload evidence but never clear it, so one
     * conditional clean re-open cannot hide an uploaded archive; an unskippable clean
     * re-open or reassignment still clears the taint.
     *
     * @param AnalysisUnit    $analysisUnit - Parsed unit supplying top-level statements when the call has no enclosing function.
     * @param Expr\MethodCall $call - extractTo() call whose receiver variable is being traced.
     *
     * @return bool - true when the replayed events leave the receiver possibly request-tainted at the call
     */
    private function hasRequestControlledArchiveSource(AnalysisUnit $analysisUnit, Expr\MethodCall $call): bool
    {
        // Property fetches and chained receivers carry no traceable binding; stay silent.
        if (!$call->var instanceof Expr\Variable || !is_string($call->var->name)) {
            return false;
        }

        $callPosition = $call->getStartFilePos();
        // Without byte offsets the event order cannot be proven; stay safe and bail.
        if ($callPosition < 0) {
            return false;
        }

        $variableName     = $call->var->name;
        $scope            = SecurityNodeHelper::enclosingFunctionLike($call);
        $statements       = $scope instanceof Node\FunctionLike ? ($scope->getStmts() ?? []) : $analysisUnit->statements;
        $events           = $this->receiverSourceEvents(array_values($statements), $variableName, $callPosition);
        $sinkAncestorIds  = SecurityNodeHelper::ancestorIdsWithin($call, $scope);
        $hasTaintedSource = false;
        // Replay the variable's history in order; the state left at the extraction call is what counts.
        foreach ($events as $event) {
            // Events inside nested closures do not rebind this scope's variable.
            if (SecurityNodeHelper::enclosingFunctionLike($event) !== $scope) {
                continue;
            }

            $isSkippable = SecurityNodeHelper::isSkippableBeforeSink($event, $scope, $sinkAncestorIds);

            // A reassignment rebinds the variable: only a PharData built from request input carries taint.
            if ($event instanceof Expr\Assign) {
                $constructsTainted = $this->isTaintedArchiveConstruction($event->expr);
                // A skippable rebind can add upload evidence but never clear it.
                $hasTaintedSource = $isSkippable ? ($hasTaintedSource || $constructsTainted) : $constructsTainted;
                continue;
            }

            if ($event instanceof Expr\MethodCall) {
                $pathArg = SecurityNodeHelper::argumentValue($event->args, 0);
                // An open call with no path argument tells us nothing about the archive's origin.
                if ($pathArg === null) {
                    continue;
                }

                $opensTainted = SecurityNodeHelper::containsUserInput($pathArg);
                // An open call replaces the backing archive; a skippable one only ever strengthens the taint.
                $hasTaintedSource = $isSkippable ? ($hasTaintedSource || $opensTainted) : $opensTainted;
            }
        }

        return $hasTaintedSource;
    }

    /**
     * Collect the receiver variable's source-defining events before the extraction call, in byte order.
     *
     * @param list<Node\Stmt> $statements - Statements of the receiver's own scope.
     * @param string          $variableName - Receiver variable name at the extraction call.
     * @param int             $callPosition - Byte offset of the extraction call bounding the search.
     *
     * @return list<Node> - assignments to the variable and open calls on it, sorted by position so the
     *                    caller can replay them into the source-taint state at the sink
     */
    private function receiverSourceEvents(array $statements, string $variableName, int $callPosition): array
    {
        $events = (new NodeFinder())->find(
            $statements,
            static fn(Node $candidate): bool => $candidate->getStartFilePos() >= 0
                                                && $candidate->getStartFilePos() < $callPosition
                                                && (
                                                    ($candidate instanceof Expr\Assign
                                                     && $candidate->var instanceof Expr\Variable
                                                     && $candidate->var->name === $variableName)
                                                    || ($candidate instanceof Expr\MethodCall
                                                        && $candidate->var instanceof Expr\Variable
                                                        && $candidate->var->name === $variableName
                                                        && SecurityNodeHelper::methodName($candidate) === 'open')
                                                ),
        );
        usort($events, static fn(Node $left, Node $right): int => $left->getStartFilePos() <=> $right->getStartFilePos());

        return $events;
    }

    /**
     * Check whether an assignment expression constructs an archive from request input.
     *
     * @param Expr $expr - Right-hand side of a receiver-variable assignment.
     *
     * @return bool - true only for `new PharData($request-tainted)`; ZipArchive construction takes no path, so
     *              every other assignment shape rebinds the variable to a clean or unknown archive
     */
    private function isTaintedArchiveConstruction(Expr $expr): bool
    {
        // Only PharData takes its archive path in the constructor; every other assignment reads as clean.
        if (!$expr instanceof Expr\New_ || !SecurityNodeHelper::hasMatchingClassName($expr->class, ['PharData'])) {
            return false;
        }

        $pathArg = SecurityNodeHelper::argumentValue($expr->args, 0);

        return $pathArg !== null && SecurityNodeHelper::containsUserInput($pathArg);
    }

    /**
     * Build the request-controlled archive-source finding.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path recorded on the finding.
     * @param Node         $node - extractTo() call flagged as unsafe; its start line locates the finding.
     *
     * @return Finding - Security finding for the tainted-source shape, distinct from the tainted-destination message.
     */
    private function sourceFinding(AnalysisUnit $analysisUnit, Node $node): Finding
    {
        // The claim is deliberately narrow: the archive SOURCE is request-controlled; entry paths are not inspected.
        return new Finding(
            ruleId:      self::ID,
            message:     'Archive extraction of a request-controlled archive source detected.',
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Treat uploaded archives as untrusted: validate entry names and sizes against traversal before extraction, and extract into an isolated directory.',
            metadata:    [
                'sink'  => 'extractTo',
                'taint' => 'archive-source',
            ],
        );
    }

    /**
     * Build the unsafe archive extraction finding.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path recorded on the finding.
     * @param Node         $node - extractTo() call flagged as unsafe; its start line locates the finding.
     *
     * @return Finding - Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node): Finding
    {
        // Request-controlled extraction enables path traversal, so flag it as a warning with remediation guidance.
        return new Finding(
            ruleId:      self::ID,
            message:     'Archive extraction with request-controlled destination or entries detected.',
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Extract only to a controlled temporary directory, validate archive entries, and reject traversal paths.',
            metadata:    [
                'sink' => 'extractTo',
            ],
        );
    }
}
