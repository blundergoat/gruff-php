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
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Detects documented public methods whose parameters lack matching @param tags.
 */
final readonly class MissingParamTagRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing @param tag findings.
     */
    public const ID = 'docs.missing-param-tag';

    /**
     * Describe the missing @param tag rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing @param tag',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find documented public function-like declarations with undocumented parameters.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     * @return list<Finding> Findings for missing parameter tags.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder     = new NodeFinder();
        $nodes      = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            if ($node instanceof ClassMethod && !$node->isPublic()) {
                continue;
            }

            $docComment = $node->getDocComment();

            if ($docComment === null || $node->params === []) {
                continue;
            }

            $docText = $docComment->getText();

            if (!$this->hasContractDoc($docText)) {
                continue;
            }

            $documentedParams = $this->extractParamNames($docText);
            $symbol           = CyclomaticComplexityRule::resolveSymbol($node);

            foreach ($node->params as $param) {
                if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                $paramName = $param->var->name;

                if (in_array($paramName, $documentedParams, true)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     sprintf('Parameter $%s in %s has no @param tag.', $paramName, $symbol),
                    filePath:    $unit->file->displayPath,
                    line:        $param->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $symbol,
                    remediation: sprintf('Add @param tag for $%s.', $paramName),
                    metadata:    ['parameter' => $paramName],
                );
            }
        }

        return $findings;
    }

    /**
     * Extract parameter names documented by @param tags.
     *
     * @param string $docText Raw docblock text.
     * @return list<string> Parameter names found in the docblock.
     */
    public static function extractParamNames(string $docText): array
    {
        $result = [];
        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            if (preg_match('/@param\s+.+?\s+\$(\w+)\b/', $line, $matches) !== 1) {
                continue;
            }

            $result[] = $matches[1];
        }

        return $result;
    }

    /**
     * Check whether a docblock carries enough contract text to require @param tags.
     *
     * @return bool True when parameter tags should be enforced.
     */
    private function hasContractDoc(string $docText): bool
    {
        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            return true;
        }

        return preg_match('/@(param|return|throws|var|template|phpstan-param|psalm-param)\b/', $docText) === 1;
    }
}
