<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags an `@param` tag naming a parameter the callable's signature no longer has, so the user can remove
 * or rename documentation that has drifted out of sync with the code.
 *
 * Runs per file over documented function-likes, collecting the documented parameter names and the real
 * ones, then reporting every documented name with no matching parameter. Warning, high confidence, since a
 * stale tag actively misleads a reader.
 */
final readonly class StaleParamTagRule implements RuleInterface
{
    /**
     * Stable rule identifier for stale @param tag findings.
     */
    public const ID = 'docs.stale-param-tag';

    /**
     * Describes the stale `@param` tag rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, high confidence).
     */
    public function definition(): RuleDefinition
    {
        // Warning, not advisory: a @param naming a parameter that no longer exists actively misleads a reader.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Stale @param tag',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports each `@param` tag that no longer matches a signature parameter.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for stale @param tags.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        // Check every documented method and function in the file.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $docComment = $node->getDocComment();

            // An undocumented callable has no tags to check.
            if ($docComment === null) {
                continue;
            }

            $docText          = $docComment->getText();
            $documentedParams = MissingParamTagRule::extractParamNames($docText);

            // Nothing to verify when the docblock documents no parameters.
            if ($documentedParams === []) {
                continue;
            }

            $actualParams = [];

            // Index the parameters the signature actually declares.
            foreach ($node->params as $param) {
                // Only a plain named parameter can be matched by name.
                if ($param->var instanceof Variable && is_string($param->var->name)) {
                    $actualParams[$param->var->name] = true;
                }
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            // Check each documented parameter name against the real ones.
            foreach ($documentedParams as $docParam) {
                // A documented name that still matches a parameter is fine.
                if (isset($actualParams[$docParam])) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     sprintf('@param $%s in %s does not match any parameter.', $docParam, $symbol),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $node->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $symbol,
                    remediation: sprintf('Remove or update the stale @param $%s tag.', $docParam),
                    metadata:    ['parameter' => $docParam],
                );
            }
        }

        return $findings;
    }
}
