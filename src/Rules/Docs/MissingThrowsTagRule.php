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
use PhpParser\Node;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassLike;
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory because an undocumented throw is a contract gap, not a defect; callers tune severity in config.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for missing @throws documentation.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            // User view: choose the findings list branch for this case.
            if ($node instanceof ClassMethod && !$node->isPublic()) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes a safe findings list default.
            if (!$this->hasDirectThrow($node->stmts ?? [])) {
                continue;
            }

            $docComment = $node->getDocComment();

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($docComment === null) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (str_contains($docComment->getText(), '@throws')) {
                continue;
            }

            // User view: choose the findings list branch for this case.
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

    /**
     * Check whether a statement list throws in its own lexical scope.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param array<Node> $statements - Function-like body statements to search.
     *
     * @return bool - true when a `throw` sits directly in this scope; throws inside nested closures, arrow
     *              functions, anonymous classes, or nested functions belong to those scopes' own contracts
     */
    private function hasDirectThrow(array $statements): bool
    {
        // One direct throw anywhere in the body is enough to require an @throws tag on the docblock.
        // User view: add each item that can appear in findings list.
        foreach ($statements as $statement) {
            // User view: choose the findings list branch for this case.
            if ($this->containsDirectThrow($statement)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively search one node for a same-scope throw, pruning nested scopes.
     *
     * This is why the user is never asked to document a callback's exception on the
     * outer method. Immediately invoked closures are pruned too: their throw
     * propagates like any called function's, and this rule documents lexical throws only.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Node to inspect.
     *
     * @return bool - true when the node is or directly contains a throw before any nested scope boundary
     */
    private function containsDirectThrow(Node $node): bool
    {
        // Found a throw that belongs to this method's own contract.
        // User view: choose the findings list branch for this case.
        if ($node instanceof Throw_) {
            return true;
        }

        // Scope boundary: closures, arrow functions, nested functions, and (anonymous) class
        // bodies own their throws; they are not part of the enclosing method's contract.
        // User view: choose the findings list branch for this case.
        if ($node instanceof FunctionLike || $node instanceof ClassLike) {
            return false;
        }

        // Keep descending through ordinary statements and expressions.
        // User view: add each item that can appear in findings list.
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;

            // Single child node: recurse straight into it.
            // User view: choose the findings list branch for this case.
            if ($subNode instanceof Node && $this->containsDirectThrow($subNode)) {
                return true;
            }

            // Child lists (statement bodies, argument lists): recurse into each node they hold.
            // User view: choose the findings list branch for this case.
            if (is_array($subNode)) {
                // User view: add each item that can appear in findings list.
                foreach ($subNode as $child) {
                    // User view: choose the findings list branch for this case.
                    if ($child instanceof Node && $this->containsDirectThrow($child)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
