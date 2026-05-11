<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Waste;

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

final readonly class UnusedParameterRule implements RuleInterface
{
    public const ID = 'waste.unused-parameter';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Unused parameter',
            pillar: Pillar::DeadCode,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $findings = [];

        foreach ($this->analysableNodes($unit, $finder) as $node) {
            array_push($findings, ...$this->findingsForNode($unit, $definition, $finder, $node));
        }

        return $findings;
    }

    /**
     * @return list<ClassMethod|Function_>
     */
    private function analysableNodes(AnalysisUnit $unit, NodeFinder $finder): array
    {
        $foundNodes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof Function_ || $node instanceof ClassMethod;
        });
        $nodes = [];

        foreach ($foundNodes as $node) {
            if (!$node instanceof ClassMethod && !$node instanceof Function_) {
                continue;
            }

            if ($node instanceof ClassMethod && !$this->isAnalysableMethod($node)) {
                continue;
            }

            if (($node->stmts ?? []) !== [] && $node->params !== []) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function isAnalysableMethod(ClassMethod $method): bool
    {
        if ($method->isAbstract() || $this->isMagicContractMethod($method)) {
            return false;
        }

        if ($method->isPrivate()) {
            return true;
        }

        return !$this->hasExternalMethodContract($method);
    }

    private function isMagicContractMethod(ClassMethod $method): bool
    {
        $name = strtolower($method->name->toString());

        return str_starts_with($name, '__') && $name !== '__construct';
    }

    private function hasExternalMethodContract(ClassMethod $method): bool
    {
        if ($this->hasOverrideAttribute($method) || $this->hasInheritDoc($method)) {
            return true;
        }

        $parent = $method->getAttribute('parent');

        if ($parent instanceof Node\Stmt\Class_) {
            return $parent->extends !== null || $parent->implements !== [];
        }

        if ($parent instanceof Node\Stmt\Enum_) {
            return $parent->implements !== [];
        }

        return false;
    }

    private function hasOverrideAttribute(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if (strtolower($attribute->name->getLast()) === 'override') {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasInheritDoc(ClassMethod $method): bool
    {
        $docComment = $method->getDocComment();

        return $docComment !== null
            && preg_match('/\\{?@inheritdoc\\b\\}?/i', $docComment->getText()) === 1;
    }

    /**
     * @param ClassMethod|Function_ $node
     * @return list<Finding>
     */
    private function findingsForNode(
        AnalysisUnit $unit,
        RuleDefinition $definition,
        NodeFinder $finder,
        ClassMethod|Function_ $node,
    ): array {
        $usedNames = $this->usedVariableNames($node, $finder);
        $findings = [];

        foreach ($this->parameterNames($node) as $name => $param) {
            if (!isset($usedNames[$name])) {
                $findings[] = $this->findingForParameter($unit, $definition, $node, $name, $param);
            }
        }

        return $findings;
    }

    /**
     * @param ClassMethod|Function_ $node
     * @return array<string, \PhpParser\Node\Param>
     */
    private function parameterNames(ClassMethod|Function_ $node): array
    {
        $paramNames = [];

        foreach ($node->params as $param) {
            if ($param->flags !== 0) {
                continue;
            }

            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $paramNames[$param->var->name] = $param;
            }
        }

        return $paramNames;
    }

    /**
     * @param ClassMethod|Function_ $node
     * @return array<string, true>
     */
    private function usedVariableNames(ClassMethod|Function_ $node, NodeFinder $finder): array
    {
        $usedNames = [];
        $usedVars = $finder->find($node->stmts ?? [], static function (Node $child): bool {
            return $child instanceof Variable
                && is_string($child->name)
                && self::isVariableUse($child);
        });

        foreach ($usedVars as $var) {
            /** @var Variable $var */
            if (is_string($var->name)) {
                $usedNames[$var->name] = true;
            }
        }

        return $usedNames;
    }

    private static function isVariableUse(Variable $variable): bool
    {
        $parent = $variable->getAttribute('parent');

        return !$parent instanceof Node\Stmt\Unset_
            || !in_array($variable, $parent->vars, true);
    }

    private function findingForParameter(
        AnalysisUnit $unit,
        RuleDefinition $definition,
        ClassMethod|Function_ $node,
        string $name,
        Node\Param $param,
    ): Finding {
        $symbol = CyclomaticComplexityRule::resolveSymbol($node);

        return new Finding(
            ruleId: $definition->id,
            message: sprintf('Parameter $%s in %s is never used.', $name, $symbol),
            filePath: $unit->file->displayPath,
            line: $param->getStartLine(),
            column: $this->startColumn($unit, $param),
            severity: $definition->defaultSeverity,
            pillar: $definition->pillar,
            tier: $definition->tier,
            confidence: $definition->confidence,
            symbol: $symbol,
            remediation: 'Remove the parameter or use it in the method body.',
            metadata: ['parameter' => $name],
        );
    }

    private function startColumn(AnalysisUnit $unit, Node\Param $param): ?int
    {
        $startFilePosition = $param->getStartFilePos();

        if ($startFilePosition < 0) {
            return null;
        }

        $sourceBeforeParameter = substr($unit->source, 0, $startFilePosition);
        $lineStartPosition = strrpos($sourceBeforeParameter, "\n");

        return $startFilePosition - ($lineStartPosition === false ? -1 : $lineStartPosition);
    }
}
