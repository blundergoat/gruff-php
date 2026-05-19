<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * Detects tests that access private members through reflection.
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
     * Describe the private reflection test rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
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
     * Find tests that use reflection or binding to reach private implementation details.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for private-reflection test access.
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
     * Detect reflection or closure binding nodes that expose private members.
     *
     * @return bool True when the node performs private reflection access.
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
