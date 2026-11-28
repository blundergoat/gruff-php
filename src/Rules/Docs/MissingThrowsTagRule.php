<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\FunctionComplexity;
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
            id:                  self::ID,
            name:                'Missing @throws tag',
            pillar:              Pillar::Documentation,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Advisory,
            confidence:          Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape' => 'A guard clause that throws for a state the author treats as unreachable, such as a LogicException behind an already-validated invariant.',
                    'mitigation' => 'Any throw in the method\'s own scope requires the tag, so document the guard as an internal invariant or restructure so the impossible state cannot throw.',
                ],
            ],
        );
    }

    /**
     * Reports each documented public method or named function that contains a direct throw expression but lacks an @throws tag.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for missing @throws documentation.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];


        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node - NodeIndex limits this scan to methods and named functions. */
            // A non-public method's contract is internal, so this rule leaves it alone.
            if ($node instanceof ClassMethod && !$node->isPublic()) {
                continue;
            }

            // Without a direct throw expression, this syntax-based rule requests no local @throws tag.
            if (!$this->hasDirectThrow($node->stmts ?? [])) {
                continue;
            }

            $docComment = $node->getDocComment();

            // An undocumented callable is owned by the missing-docblock rule.
            if ($docComment === null) {
                continue;
            }


            if (str_contains($docComment->getText(), '@throws')) {
                continue;
            }

            // An inherited docblock or explicit inheritance marker exempts this method from a local @throws tag.
            if ($node instanceof ClassMethod && (new DocsInheritanceHelper())->hasInheritedContractDoc($node, $analysisUnit->statements, $nodeFinder)) {
                continue;
            }

            $symbol = FunctionComplexity::resolveSymbol($node);

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
     * Reports whether a statement list contains a throw expression in its own lexical scope.
     *
     * Public so docs.missing-phpdoc asks when a callable throws by the same test.
     *
     * @param array<Node> $statements - Function-like body statements to search.
     *
     * @return bool - True when a throw expression appears outside nested functions, closures, arrow functions, and class-like declarations.
     */
    public function hasDirectThrow(array $statements): bool
    {
        // A direct throw expression anywhere in the body is enough for this syntax-based rule.
        foreach ($statements as $statement) {
            if ($this->containsDirectThrow($statement)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Searches a node for a lexical throw, excluding nested functions and class-like declarations.
     * Immediately invoked closures are excluded too; propagated exceptions are outside this rule's scope.
     *
     * @param Node $node - Node to inspect.
     *
     * @return bool - True when the node or a descendant is a throw before any nested scope boundary.
     */
    private function containsDirectThrow(Node $node): bool
    {

        if ($node instanceof Throw_) {
            return true;
        }

        // Scope boundary: closures, arrow functions, nested functions, and (anonymous) class
        // bodies own their throws; they are not part of the enclosing method's contract.
        if ($node instanceof FunctionLike || $node instanceof ClassLike) {
            return false;
        }


        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;


            if ($subNode instanceof Node && $this->containsDirectThrow($subNode)) {
                return true;
            }


            if (is_array($subNode)) {

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
