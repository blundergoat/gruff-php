<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for stale @param tags.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $docComment = $node->getDocComment();

            if ($docComment === null) {
                continue;
            }

            $docText          = $docComment->getText();
            $documentedParams = MissingParamTagRule::extractParamNames($docText);

            if ($documentedParams === []) {
                continue;
            }

            $actualParams = [];

            foreach ($node->params as $param) {
                if ($param->var instanceof Variable && is_string($param->var->name)) {
                    $actualParams[$param->var->name] = true;
                }
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            foreach ($documentedParams as $docParam) {
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
