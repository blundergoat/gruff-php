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
 * Reports extractTo() calls with request-tainted extraction arguments or archive-source evidence.
 * Instance calls match by method name; static calls additionally require ZipArchive or PharData name matching.
 *
 * Archive-source tracing follows simple variable receivers on instance calls within their function-like or file scope.
 * Findings are warnings at medium confidence; archive entry paths are not inspected.
 */
final class UnsafeArchiveExtractionRule implements RuleInterface
{
    /**
     * Stable rule identifier for unsafe archive extraction.
     */
    public const ID = 'security.unsafe-archive-extraction';

    /**
     * Describes the unsafe-archive-extraction rule for the registry and reports.
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A domain object with its own extractTo() method, called with request-derived arguments.',
                    'mitigation' => 'Instance calls are matched on the method name alone, while only the static path requires a ZipArchive or PharData receiver, so accept the finding or rename the local method.',
                ],
            ],
        );
    }

    /**
     * Reports extractTo() call shapes whose destination, entries, or traced source appear request-controlled.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for call shapes matched by the unsafe-archive heuristic.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\MethodCall::class) as $call) {

            if (SecurityNodeHelper::methodName($call) !== 'extractto') {
                continue;
            }

            // Prefer the destination/entry finding when both argument and archive-source taint are present.
            if ($this->hasRequestControlledExtractionArgument($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
                continue;
            }

            // A request-controlled archive source can carry unsafe entries even when neither extraction argument shows request taint.

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
     * Reports whether an extractTo() destination or entry-list argument contains request-input syntax.
     *
     * @param Expr\MethodCall|Expr\StaticCall $call - extractTo() call whose destination and entry-list arguments are checked.
     *
     * @return bool - True when either checked argument contains syntax recognised as request input.
     */
    private function hasRequestControlledExtractionArgument(Expr\MethodCall|Expr\StaticCall $call): bool
    {

        foreach ([0, 1] as $argumentIndex) {
            $argument = SecurityNodeHelper::argumentValue($call->args, $argumentIndex);
            if ($argument !== null && SecurityNodeHelper::containsUserInput($argument)) {

                return true;
            }
        }


        return false;
    }

    /**
     * Reports whether source tracing leaves a simple receiver variable possibly request-tainted.
     * Replays earlier same-scope assignments and open calls; an unresolved open path leaves the taint unchanged.
     * Skippable updates can add taint but cannot clear it; other updates replace it.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying top-level statements when the call has no enclosing function.
     * @param Expr\MethodCall $call - extractTo() call whose receiver variable is being traced.
     *
     * @return bool - True when replay retains taint; false for absent evidence, unsupported receivers or unavailable call offsets.
     */
    private function hasRequestControlledArchiveSource(AnalysisUnit $analysisUnit, Expr\MethodCall $call): bool
    {
        // Property fetches and chained receivers carry no traceable binding; stay silent.
        if (!$call->var instanceof Expr\Variable || !is_string($call->var->name)) {
            return false;
        }

        $callPosition = $call->getStartFilePos();
        // Without source offsets, skip archive-source tracing because event order is unknown.
        if ($callPosition < 0) {
            return false;
        }

        $variableName     = $call->var->name;
        $scope            = SecurityNodeHelper::enclosingFunctionLike($call);
        $statements       = $scope instanceof Node\FunctionLike ? ($scope->getStmts() ?? []) : $analysisUnit->statements;
        $events           = $this->receiverSourceEvents(array_values($statements), $variableName, $callPosition);
        $sinkAncestorIds  = SecurityNodeHelper::ancestorIdsWithin($call, $scope);
        $hasTaintedSource = false;

        foreach ($events as $event) {
            // Events inside nested closures do not rebind this scope's variable.
            if (SecurityNodeHelper::enclosingFunctionLike($event) !== $scope) {
                continue;
            }

            $isSkippable = SecurityNodeHelper::isSkippableBeforeSink($event, $call, $scope, $sinkAncestorIds);


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
     * Collects assignments and open calls matching the receiver name before extraction, sorted by byte offset.
     *
     * @param list<Node\Stmt> $statements - Statements of the receiver's own scope.
     * @param string $variableName - Receiver variable name at the extraction call.
     * @param int $callPosition - Byte offset of the extraction call bounding the search.
     *
     * @return list<Node> - Candidate events in byte order; the caller filters out nested function-like scopes.
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
     * Reports whether an assignment expression matches PharData construction with request-input syntax.
     *
     * @param Expr $expr - Right-hand side of a receiver-variable assignment.
     *
     * @return bool - True only when the class name matches PharData and the path contains syntax recognised as request input.
     */
    private function isTaintedArchiveConstruction(Expr $expr): bool
    {

        if (!$expr instanceof Expr\New_ || !SecurityNodeHelper::hasMatchingClassName($expr->class, ['PharData'])) {
            return false;
        }

        $pathArg = SecurityNodeHelper::argumentValue($expr->args, 0);

        return $pathArg !== null && SecurityNodeHelper::containsUserInput($pathArg);
    }

    /**
     * Builds the request-controlled archive-source finding.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path recorded on the finding.
     * @param Node         $node - extractTo() call flagged as unsafe; its start line locates the finding.
     *
     * @return Finding - Security finding for the tainted-source shape, distinct from the tainted-destination message.
     */
    private function sourceFinding(AnalysisUnit $analysisUnit, Node $node): Finding
    {
        // The finding reports only the traced archive source; this rule does not inspect archive entry paths.
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
     * Builds the unsafe-archive-extraction finding.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path recorded on the finding.
     * @param Node $node - extractTo() call flagged as unsafe; its start line locates the finding.
     *
     * @return Finding - Warning for request-controlled extraction destinations or entries.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node): Finding
    {

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
