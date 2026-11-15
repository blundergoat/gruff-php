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
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar;

/**
 * Reports a `header()` call built from request data, where an attacker's CR/LF could add headers or split the response.
 *
 * A user who wrote `header('Location: ' . $_GET['next'])` gets one warning on that line, to validate the value first.
 * Medium confidence: request data reaching header() is a likely injection point, not a proven one.
 *
 * Two values cannot carry CR/LF and stay quiet:
 * - a status line built only from `$_SERVER['SERVER_PROTOCOL']` and literal text, such as a hand-written 404;
 * - a value whose dynamic parts are all integers: an `(int)` cast, an integer literal, or a local only ever assigned one.
 */
final class HeaderInjectionRule implements RuleInterface
{
    /**
     * Stable rule identifier for header injection findings.
     */
    public const ID = 'security.header-injection';

    /**
     * Describes the rule for `list-rules` and for each finding it reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: request data reaching header() is a likely injection point, not a certain one.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Header injection risk',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            // Off unless a project sets `enabled: true`: precision-floor M02 left 9 findings across the corpus after its repair, fewer than the ten a
            // precision floor needs, so it stays opt-in until more evidence exists (ADR-034).
            isEnabledByDefault: false,
            falsePositiveShapes: [
                [
                    'shape'      => 'A header value already validated in place, for example matched against an allow-list or rebuilt through a cast, where the superglobal still appears in the expression.',
                    'mitigation' => 'Validation does not clear taint; assign the checked value to a separate local built without referencing the superglobal, then pass that local to header().',
                ],
                [
                    'shape'      => 'A header built from a server-controlled $_SERVER entry rather than attacker-supplied input.',
                    'mitigation' => 'The whole $_SERVER superglobal is classified as request-controlled, so resolve the value through validated configuration instead of reading it at the sink.',
                ],
            ],
        );
    }

    /**
     * Reports each `header()` call whose value request data can reach.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per request-fed header() call; empty when every header value is fixed, numeric or a status line.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {

            if (SecurityNodeHelper::globalFunctionName($call) !== 'header') {
                continue;
            }

            $headerLine = SecurityNodeHelper::sinkArgumentValue($call, 0);

            if ($headerLine === null || !SecurityNodeHelper::containsUserInput($headerLine)) {
                continue;
            }

            // A hand-written status line or a numbers-only value cannot smuggle a line break into the response.
            if ($this->isServerProtocolStatusLine($headerLine) || $this->isIntegerOnlyValue($analysisUnit, $headerLine)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Heuristic header() call with request-controlled data detected.',
                filePath:    $analysisUnit->file->displayPath,
                line:        $call->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Validate and normalize redirect/header values, and reject CR/LF characters before calling header().',
            );
        }

        return $findings;
    }

    /**
     * Reports whether a header value is the server's protocol token followed only by literal status text, such as a hand-written 404.
     *
     * This heuristic treats the server protocol token as CR/LF-free and requires every following part to be literal.
     *
     * @param Expr $headerLine - First argument of header().
     *
     * @return bool - True for $_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found'; false when any other part is dynamic.
     */
    private function isServerProtocolStatusLine(Expr $headerLine): bool
    {
        $headerParts   = SecurityNodeHelper::flattenedOperands($headerLine);
        $protocolPart  = array_shift($headerParts);
        $isProtocolKey = $protocolPart instanceof Expr\ArrayDimFetch && $protocolPart->var instanceof Expr\Variable
            && $protocolPart->var->name === '_SERVER' && $protocolPart->dim instanceof Scalar\String_ && $protocolPart->dim->value === 'SERVER_PROTOCOL';
        // Other $_SERVER entries are outside this narrow server-protocol exception.
        if (!$isProtocolKey) {
            return false;
        }

        $statusText = '';
        // Everything after the protocol must be the code's own text.
        foreach ($headerParts as $headerPart) {
            // A second dynamic part, such as $_GET['reason'], could carry request text after the protocol.
            if (!$headerPart instanceof Scalar\String_ && !$headerPart instanceof InterpolatedStringPart) {
                return false;
            }

            $statusText .= $headerPart->value;
        }

        // The literal text must open with whitespace and a three-digit status code; the protocol alone is not a status line.
        return preg_match('/^\s+\d{3}\b/', $statusText) === 1;
    }

    /**
     * Reports whether every dynamic header part matches the rule's integer syntax or local-assignment heuristic.
     *
     * @param AnalysisUnit $analysisUnit - File searched for the assignments to each local.
     * @param Expr         $headerLine - First argument of header().
     *
     * @return bool - True when every part is literal text or accepted by one of the rule's integer heuristics.
     */
    private function isIntegerOnlyValue(AnalysisUnit $analysisUnit, Expr $headerLine): bool
    {

        foreach (SecurityNodeHelper::flattenedOperands($headerLine) as $headerPart) {
            $isLiteralText = $headerPart instanceof Scalar\String_ || $headerPart instanceof InterpolatedStringPart;
            // A part outside those integer heuristics, such as a raw request value, keeps the finding.
            if (!$isLiteralText && !$this->isIntegerExpression($headerPart) && !$this->isLocalOnlyEverAnInteger($analysisUnit, $headerPart)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reports whether an expression can only produce an integer.
     *
     * @param Node $expression - Header part or assigned value to inspect.
     *
     * @return bool - True for an (int) cast, an integer literal, or a full ternary whose two arms are both integers.
     */
    private function isIntegerExpression(Node $expression): bool
    {
        // A cast or integer literal prints only digits and a sign.
        if ($expression instanceof Expr\Cast\Int_ || $expression instanceof Scalar\Int_) {
            return true;
        }

        // The ternary's condition never reaches the header, so only its two arms decide; a short `?:` returns its condition.
        return $expression instanceof Expr\Ternary && $expression->if !== null
            && $this->isIntegerExpression($expression->if) && $this->isIntegerExpression($expression->else);
    }

    /**
     * Reports whether visible assignments let the header heuristic treat a local as integer-only.
     *
     * @param AnalysisUnit $analysisUnit - File searched for the local's assignments.
     * @param Node         $headerPart - Header part to inspect.
     *
     * @return bool - True after an earlier integer assignment when visible writes remain integers; calls may still receive the local.
     */
    private function isLocalOnlyEverAnInteger(AnalysisUnit $analysisUnit, Node $headerPart): bool
    {
        // Passing the local to a call stays allowed, even though a by-reference callee could replace its value.
        return LocalAssignmentEvidence::onlyEverHolds($analysisUnit, $headerPart, fn(Expr $assigned): bool => $this->isIntegerExpression($assigned), true);
    }
}
