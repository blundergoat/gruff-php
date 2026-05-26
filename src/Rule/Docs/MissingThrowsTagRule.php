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
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Detects documented methods that throw without declaring an @throws contract.
 */
final readonly class MissingThrowsTagRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing @throws tag findings.
     */
    public const ID = 'docs.missing-throws-tag';

    /**
     * Describe the missing @throws tag rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing @throws tag',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find documented public functions that throw without an @throws tag.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for missing @throws documentation.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            if ($node instanceof ClassMethod && !$node->isPublic()) {
                continue;
            }

            $throws = $nodeFinder->findInstanceOf($node->stmts ?? [], Throw_::class);

            if ($throws === []) {
                continue;
            }

            $docComment = $node->getDocComment();

            if ($docComment === null) {
                continue;
            }

            if (str_contains($docComment->getText(), '@throws')) {
                continue;
            }

            if ($node instanceof ClassMethod && (new DocsInheritanceHelper())->hasInheritedContractDoc($node, $analysisUnit->statements, $nodeFinder)) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s throws but needs a @throws tag naming the exception type and the condition that triggers it (one plain-English clause; not a restatement of the exception class name).', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Add an `@throws SomeException Why this is raised.` tag to the docblock. This rule wants content, not boilerplate - the description should answer "what condition triggers this throw and how should the caller prepare."',
            );
        }

        return $findings;
    }
}
