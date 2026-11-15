<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * Flags reflection construction and selected binding calls in discovered test scopes.
 * Matches syntax without resolving member visibility or the receivers of instance calls.
 * Emits at most one warning per test scope, at high confidence.
 */
final readonly class PrivateReflectionRule implements RuleInterface
{
    /**
     * Stable identifier for the private reflection rule.
     */
    public const ID = 'test-quality.private-reflection';

    /**
     * Reflection classes that can expose private implementation details.
     */
    private const REFLECTION_CLASSES = ['reflectionmethod', 'reflectionclass', 'reflectionproperty'];

    /**
     * Describes the private-reflection rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {

        return new RuleDefinition(
            id:              self::ID,
            name:            'Private member reflection',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports test scopes containing a recognized reflection or binding pattern.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - At most one finding per matching test scope; empty when no scope matches.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $nodeFinder = new NodeFinder();
        $findings   = [];


        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $reflectionNode = null;

            foreach ($nodeFinder->find(
                $scope->statements,
                static fn (Node $node): bool => $node instanceof Expr\New_
                    || $node instanceof Expr\MethodCall
                    || $node instanceof Expr\StaticCall,
            ) as $node) {

                if ($this->isPrivateReflectionNode($node)) {
                    $reflectionNode = $node;
                    break;
                }
            }


            if (!$reflectionNode instanceof Node) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s uses reflection to reach implementation details.', $scope->symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $reflectionNode->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                symbol:      $scope->symbol,
                remediation: 'Test behavior through public contracts instead of private members.',
            );
        }

        return $findings;
    }

    /**
     * Matches reflection construction, Closure::bind and selected instance-call names.
     *
     * @param Node $node - Candidate AST node (a new-expression, static call, or method call) to classify.
     *
     * @return bool - True for a recognized pattern, without proving private-member access or instance receiver types.
     */
    private function isPrivateReflectionNode(Node $node): bool
    {
        if ($node instanceof Expr\New_ && $node->class instanceof Name) {

            return in_array(strtolower($node->class->getLast()), self::REFLECTION_CLASSES, true);
        }


        if ($node instanceof Expr\StaticCall && $node->class instanceof Name) {
            $name      = TestQualityNodeHelper::callName($node);
            $className = strtolower($node->class->getLast());


            return $className === 'closure' && $name === 'bind';
        }


        if ($node instanceof Expr\MethodCall) {
            $name = TestQualityNodeHelper::callName($node);

            if ($name === 'setaccessible'
                && TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($node)) === true
            ) {

                return true;
            }


            return $name === 'bindto';
        }


        return false;
    }
}
