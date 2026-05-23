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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for unsafe XML loading.
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

        return $findings;
    }

    /**
     * Build xml method findings for the security rule.
     *
     * @return list<Finding>
     */
    private function xmlMethodFindings(AnalysisUnit $analysisUnit, Expr\MethodCall|Expr\StaticCall $call): array
    {
        $method = SecurityNodeHelper::methodName($call);
        if (!in_array($method, ['load', 'loadxml', 'open', 'xml'], true)) {
            return [];
        }

        $xmlArg = SecurityNodeHelper::argumentValue($call->args, 0);
        if ($xmlArg === null || !SecurityNodeHelper::containsUserInput($xmlArg)) {
            return [];
        }

        $optionsIndex = in_array($method, ['open', 'xml'], true) ? 2 : 1;
        if ($this->hasLibxmlNonetArgument($call->args, $optionsIndex)) {
            return [];
        }

        return [$this->finding($analysisUnit, $call, $method)];
    }

    /**
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args
     * @return bool True when an argument from the given index contains LIBXML_NONET.
     */
    private function hasLibxmlNonetArgument(array $args, int $startIndex): bool
    {
        foreach ($args as $index => $arg) {
            if (!is_int($index) || $index < $startIndex || !$arg instanceof Node\Arg) {
                continue;
            }

            if ($this->containsLibxmlNonet($arg->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool True when the node contains the LIBXML_NONET constant.
     */
    private function containsLibxmlNonet(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
            // Match the explicit network-blocking flag accepted by PHP XML loaders.
            return SecurityNodeHelper::constantName($candidate) === 'LIBXML_NONET';
        }) instanceof Node;
    }

    /**
     * Build the unsafe XML loading finding.
     *
     * @return Finding Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $sink): Finding
    {
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
