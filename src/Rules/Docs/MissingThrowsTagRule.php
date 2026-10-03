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
 * Flags a documented public method or function that throws in its own scope without declaring an `@throws`
 * contract, so the user tells callers what to catch and when.
 *
 * Runs per file over public function-likes. It looks only for a throw in the callable's own lexical scope
 * (throws inside nested closures, arrow functions, or nested classes belong to those scopes), and skips
 * declarations that inherit a documented contract. Advisory, medium confidence.
 */
final readonly class MissingThrowsTagRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing @throws tag findings.
     */
    public const ID = 'docs.missing-throws-tag';

    /**
     * Describes the missing `@throws` tag rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, medium confidence).
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A guard clause that throws for a state the author treats as unreachable, such as a LogicException behind an already-validated invariant.',
                    'mitigation' => 'Any throw in the method\'s own scope requires the tag, so document the guard as an internal invariant or restructure so the impossible state cannot throw.',
                ],
            ],
        );
    }

    /**
     * Reports each documented public function-like that throws without an `@throws` tag.
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

        // Check every method and function in the file.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            // A non-public method's contract is internal, so this rule leaves it alone.
            if ($node instanceof ClassMethod && !$node->isPublic()) {
                continue;
            }

            // Nothing to document when the body does not throw in its own scope.
            if (!$this->hasDirectThrow($node->stmts ?? [])) {
                continue;
            }

            $docComment = $node->getDocComment();

            // An undocumented callable is owned by the missing-docblock rule.
            if ($docComment === null) {
                continue;
            }

            // A docblock that already declares its throws is fine.
            if (str_contains($docComment->getText(), '@throws')) {
                continue;
            }

            // An inherited, documented contract already covers the throw.
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
     * Reports whether a statement list throws in its own lexical scope.
     *
     * @param array<Node> $statements - Function-like body statements to search.
     *
     * @return bool - true when a `throw` sits directly in this scope; throws inside nested closures, arrow
     *              functions, anonymous classes, or nested functions belong to those scopes' own contracts
     */
    private function hasDirectThrow(array $statements): bool
    {
        // One direct throw anywhere in the body is enough to require an @throws tag on the docblock.
        foreach ($statements as $statement) {
            if ($this->containsDirectThrow($statement)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively searches one node for a same-scope throw, pruning nested scopes.
     *
     * This is why the user is never asked to document a callback's exception on the
     * outer method. Immediately invoked closures are pruned too: their throw
     * propagates like any called function's, and this rule documents lexical throws only.
     *
     * @param Node $node - Node to inspect.
     *
     * @return bool - true when the node is or directly contains a throw before any nested scope boundary
     */
    private function containsDirectThrow(Node $node): bool
    {
        // Found a throw that belongs to this method's own contract.
        if ($node instanceof Throw_) {
            return true;
        }

        // Scope boundary: closures, arrow functions, nested functions, and (anonymous) class
        // bodies own their throws; they are not part of the enclosing method's contract.
        if ($node instanceof FunctionLike || $node instanceof ClassLike) {
            return false;
        }

        // Keep descending through ordinary statements and expressions.
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;

            // Single child node: recurse straight into it.
            if ($subNode instanceof Node && $this->containsDirectThrow($subNode)) {
                return true;
            }

            // Child lists (statement bodies, argument lists): recurse into each node they hold.
            if (is_array($subNode)) {
                // Recurse into each child node the list holds.
                foreach ($subNode as $child) {
                    if ($child instanceof Node && $this->containsDirectThrow($child)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
