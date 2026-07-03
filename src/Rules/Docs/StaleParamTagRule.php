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
 * Detects @param tags whose names no longer match the callable signature.
 */
final readonly class StaleParamTagRule implements RuleInterface
{
    /**
     * Stable rule identifier for stale @param tag findings.
     */
    public const ID = 'docs.stale-param-tag';

    /**
     * Describe the stale @param tag rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
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
     * Find @param tags that no longer match function parameters.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $docComment = $node->getDocComment();

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($docComment === null) {
                continue;
            }

            $docText          = $docComment->getText();
            $documentedParams = MissingParamTagRule::extractParamNames($docText);

            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if ($documentedParams === []) {
                continue;
            }

            $actualParams = [];

            // User view: add each item that can appear in findings list.
            foreach ($node->params as $param) {
                // User view: choose the findings list branch for this case.
                if ($param->var instanceof Variable && is_string($param->var->name)) {
                    $actualParams[$param->var->name] = true;
                }
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            // User view: add each item that can appear in findings list.
            foreach ($documentedParams as $docParam) {
                // User view: choose the findings list branch for this case.
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
