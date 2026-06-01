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
use PhpParser\NodeFinder;

/**
 * Detects XML loading of request-controlled data without network restrictions.
 */
final class UnsafeXmlLoadingRule implements RuleInterface
{
    /**
     * Stable rule identifier for unsafe XML loading.
     */
    public const ID = 'security.unsafe-xml-loading';

    /**
     * Describe the unsafe XML loading rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: taint tracking is heuristic and LIBXML_NONET may be set out of view, so warn not error.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unsafe XML loading',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find XML loaders that receive request-controlled data without LIBXML_NONET.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unsafe XML loading.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if (!in_array($name, ['simplexml_load_file', 'simplexml_load_string'], true)) {
                continue;
            }

            $xmlArg = SecurityNodeHelper::argumentValue($call->args, 0);
            if ($xmlArg !== null && SecurityNodeHelper::containsUserInput($xmlArg) && !$this->hasLibxmlNonetArgument($call->args, 2)) {
                $findings[] = $this->finding($analysisUnit, $call, $name);
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\MethodCall::class) as $call) {
            array_push($findings, ...$this->xmlMethodFindings($analysisUnit, $call));
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $call) {
            array_push($findings, ...$this->xmlMethodFindings($analysisUnit, $call));
        }

        // Empty when every loader was fed trusted data or already passed LIBXML_NONET; a clean file, not an error.
        return $findings;
    }

    /**
     * Build XML-loading findings for DOMDocument/XMLReader-style method and static calls.
     *
     * @param AnalysisUnit                    $analysisUnit - Parsed unit supplying the display path for any finding.
     * @param Expr\MethodCall|Expr\StaticCall $call - Loader call to inspect (`load`, `loadXML`, `open`, `xml`).
     *
     * @return list<Finding> - One finding when the loader takes request-controlled data without LIBXML_NONET, else empty.
     */
    private function xmlMethodFindings(AnalysisUnit $analysisUnit, Expr\MethodCall|Expr\StaticCall $call): array
    {
        $method = SecurityNodeHelper::methodName($call);
        if (!in_array($method, ['load', 'loadxml', 'open', 'xml'], true)) {
            // Not an XML-loading entry point, so it cannot trigger external-entity or network fetches; skip it.
            return [];
        }

        $xmlArg = SecurityNodeHelper::argumentValue($call->args, 0);
        if ($xmlArg === null || !SecurityNodeHelper::containsUserInput($xmlArg)) {
            // The XML payload is trusted (or absent), so an unrestricted loader carries no injection risk here.
            return [];
        }

        // DOMDocument load/loadXML put options at index 1; XMLReader open/xml take encoding first, so options sit at 2.
        $optionsIndex = in_array($method, ['open', 'xml'], true) ? 2 : 1;
        if ($this->hasLibxmlNonetArgument($call->args, $optionsIndex)) {
            // The caller passed LIBXML_NONET, which blocks the network fetch this rule guards against; not a finding.
            return [];
        }

        // Request-controlled XML reaches a network-capable loader with no LIBXML_NONET: the exact unsafe shape to flag.
        return [$this->finding($analysisUnit, $call, $method)];
    }

    /**
     * Check whether any positional options argument at or after the given index passes LIBXML_NONET.
     *
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args - Call args; string-keyed named args are skipped.
     * @param int $startIndex - First positional index the options flags can appear at; varies by loader signature.
     *
     * @return bool - True when an argument from the given index contains LIBXML_NONET.
     */
    private function hasLibxmlNonetArgument(array $args, int $startIndex): bool
    {
        foreach ($args as $index => $arg) {
            if (!is_int($index) || $index < $startIndex || !$arg instanceof Node\Arg) {
                continue;
            }

            if ($this->containsLibxmlNonet($arg->value)) {
                // A flags argument carries LIBXML_NONET, so the network-blocking guardrail is present; report safe.
                return true;
            }
        }

        // No positional flags argument referenced LIBXML_NONET, so the call is treated as network-enabled.
        return false;
    }

    /**
     * Test whether an argument expression mentions the LIBXML_NONET constant anywhere in its subtree.
     *
     * @param Node $node - Argument value node to search (a bare constant, a bitmask expression, etc.).
     *
     * @return bool - True when the node contains the LIBXML_NONET constant.
     */
    private function containsLibxmlNonet(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        // Search the whole subtree so the flag is honoured even inside a `LIBXML_NONET | LIBXML_NOENT` bitmask.
        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
            // Match the explicit network-blocking flag accepted by PHP XML loaders.
            return SecurityNodeHelper::constantName($candidate) === 'LIBXML_NONET';
        }) instanceof Node;
    }

    /**
     * Build the unsafe XML loading finding for one flagged loader call.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying the display path recorded on the finding.
     * @param Node $node - Loader call node; its start line locates the finding in source.
     * @param string $sink - Loader name (e.g. `loadXML`) put in the message and metadata so triage knows the call.
     *
     * @return Finding - Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $sink): Finding
    {
        // Warning, not Error: a flagged loader may still be safe if entity loading is disabled elsewhere.
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('XML loading with request-controlled data and no LIBXML_NONET detected: %s.', $sink),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Use LIBXML_NONET and disable external entity/network loading before parsing request-controlled XML.',
            metadata:    [
                'sink' => $sink,
            ],
        );
    }
}
