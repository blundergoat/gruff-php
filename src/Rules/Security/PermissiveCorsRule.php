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

/**
 * Flags a wildcard CORS origin combined with credentialed requests.
 *
 * Literal header() calls are grouped by their nearest function-like scope or by file scope.
 * A matching pair produces a warning even when the calls occur on mutually exclusive paths.
 *
 * Non-literal header values are skipped and can instead be inspected by security.header-injection.
 */
final class PermissiveCorsRule implements RuleInterface
{
    /** Stable rule identifier for permissive-CORS findings. */
    public const ID = 'security.permissive-cors';

    /**
     * Describes the permissive-CORS rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {

        return new RuleDefinition(
            id:              self::ID,
            name:            'Permissive CORS with credentials',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            description:     'Wildcard Access-Control-Allow-Origin combined with Access-Control-Allow-Credentials: true.',
            falsePositiveShapes: [
                [
                    'shape'      => 'A wildcard origin and a credentialed CORS header emitted in one function on mutually exclusive branches, one for a public endpoint and one for an authenticated endpoint.',
                    'mitigation' => 'Headers are grouped per function-like scope rather than per execution path, so move the two postures into separate functions.',
                ],
            ],
        );
    }

    /**
     * Reports a wildcard CORS origin paired with credentialed CORS in the same scope.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for scopes whose literal header text matches both CORS patterns.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        /** @var array<string, array{wildcardLine: int|null, credentials: bool}> $scopes - CORS header state per scope. */
        $scopes = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {

            if (SecurityNodeHelper::globalFunctionName($call) !== 'header') {
                continue;
            }

            $firstArg = SecurityNodeHelper::sinkArgumentValue($call, 0);
            // A non-literal header value is left to the header-injection rule.
            if (!$firstArg instanceof Scalar\String_) {
                continue;
            }

            // A wildcard origin matters only when this scope also enables credentials.
            $isWildcard = preg_match('/access-control-allow-origin\s*:\s*\*/i', $firstArg->value) === 1;
            // Match credentialed CORS headers so the paired wildcard origin can be flagged.
            $isCredentials = preg_match('/access-control-allow-credentials\s*:\s*true/i', $firstArg->value) === 1;

            if (!$isWildcard && !$isCredentials) {
                continue;
            }

            $scopeKey            = $this->scopeKey($call);
            $scopes[$scopeKey] ??= ['wildcardLine' => null, 'credentials' => false];

            if ($isWildcard && $scopes[$scopeKey]['wildcardLine'] === null) {
                $scopes[$scopeKey]['wildcardLine'] = $call->getStartLine();
            }


            if ($isCredentials) {
                $scopes[$scopeKey]['credentials'] = true;
            }
        }

        $findings = [];

        foreach ($scopes as $scope) {

            if ($scope['wildcardLine'] === null || !$scope['credentials']) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Wildcard CORS origin (Access-Control-Allow-Origin: *) is combined with Access-Control-Allow-Credentials: true, exposing credentialed responses to any origin.',
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope['wildcardLine'],
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Echo a specific allowlisted origin instead of "*" when sending credentials, or drop Access-Control-Allow-Credentials for public, credential-free endpoints.',
            );
        }

        return $findings;
    }

    /**
     * Builds a stable grouping key for the enclosing function-like scope.
     *
     * @param Node $node - Header call node.
     *
     * @return string - Scope key (function-like object id, or "file" at top level).
     */
    private function scopeKey(Node $node): string
    {
        $current = $node->getAttribute('parent');

        while ($current instanceof Node) {
            if ($current instanceof FunctionLike) {
                // Function-like identity keeps unrelated local header calls from merging.
                return 'fn:' . spl_object_id($current);
            }

            $parent  = $current->getAttribute('parent');
            $current = $parent instanceof Node ? $parent : null;
        }

        // Top-level calls share the file scope because there is no narrower owner.
        return 'file';
    }
}
