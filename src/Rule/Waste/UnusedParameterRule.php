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
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Detects function and method parameters that are never read from executable code.
 */
final readonly class UnusedParameterRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused parameter findings.
     */
    public const ID = 'waste.unused-parameter';

    /**
     * Describe the rule for the registry and reports.
     *
     * @return RuleDefinition
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unused parameter',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Flag function and method parameters that are declared but never read in the body.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     * @return list<Finding>
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $findings   = [];

        foreach ($this->analysableNodes($analysisUnit) as $node) {
            array_push($findings, ...$this->findingsForNode($analysisUnit, $definition, $nodeFinder, $node));
        }

        return $findings;
    }

    /**
     * @return list<ClassMethod|Function_>
     */
    private function analysableNodes(AnalysisUnit $analysisUnit): array
    {
        $foundNodes = NodeIndex::nodesOfAny($analysisUnit, [Function_::class, ClassMethod::class]);
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

    /**
     * Detect whether the method's parameters can be analysed for unused-ness (skips abstract, magic, and contract overrides).
     *
     * @return bool True when the method body is in scope and not bound to an external interface contract.
     */
    private function isAnalysableMethod(ClassMethod $classMethod): bool
    {
        if ($classMethod->isAbstract() || $this->isMagicContractMethod($classMethod)) {
            return false;
        }

        if ($classMethod->isPrivate()) {
            return true;
        }

        return !$this->hasExternalMethodContract($classMethod);
    }

    /**
     * Detect whether the method is a magic / contract method (`__toString`, `__get`, etc.) where parameter shape is fixed.
     *
     * @return bool True when the name begins with `__` and is not `__construct`.
     */
    private function isMagicContractMethod(ClassMethod $classMethod): bool
    {
        $name = strtolower($classMethod->name->toString());

        return str_starts_with($name, '__') && $name !== '__construct';
    }

    /**
     * Detect whether the method overrides or implements an external contract whose signature is mandatory.
     *
     * @return bool True when an Override attribute, inheritDoc marker, or `extends` / `implements` ancestor exists.
     */
    private function hasExternalMethodContract(ClassMethod $classMethod): bool
    {
        if ($this->hasOverrideAttribute($classMethod) || $this->hasInheritDoc($classMethod)) {
            return true;
        }

        $parent = $classMethod->getAttribute('parent');

        if ($parent instanceof Node\Stmt\Class_) {
            return $parent->extends !== null || $parent->implements !== [];
        }

        if ($parent instanceof Node\Stmt\Enum_) {
            return $parent->implements !== [];
        }

        return false;
    }

    /**
     * Detect whether the method carries a `#[\Override]` attribute.
     *
     * @return bool
     */
    private function hasOverrideAttribute(ClassMethod $classMethod): bool
    {
        foreach ($classMethod->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if (strtolower($attribute->name->getLast()) === 'override') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect whether the method's docblock contains `@inheritdoc` or `{@inheritdoc}`.
     *
     * @return bool
     */
    private function hasInheritDoc(ClassMethod $classMethod): bool
    {
        $docComment = $classMethod->getDocComment();

        return $docComment !== null
            // Match both block `@inheritdoc` and inline `{@inheritdoc}` inheritance markers.
            && preg_match('/\\{?@inheritdoc\\b\\}?/i', $docComment->getText()) === 1;
    }

    /**
     * @param ClassMethod|Function_ $node
     * @return list<Finding>
     */
    private function findingsForNode(
        AnalysisUnit $analysisUnit,
        RuleDefinition $definition,
        NodeFinder $nodeFinder,
        ClassMethod|Function_ $node,
    ): array {
        $usedNames = $this->usedVariableNames($node, $nodeFinder);
        $findings  = [];

        foreach ($this->parameterNames($node) as $name => $param) {
            if (!isset($usedNames[$name])) {
                $findings[] = $this->findingForParameter(
                    analysisUnit:       $analysisUnit,
                    definition: $definition,
                    node:       $node,
                    name:       $name,
                    param:      $param,
                );
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
    private function usedVariableNames(ClassMethod|Function_ $node, NodeFinder $nodeFinder): array
    {
        $usedNames = [];
        $usedVars  = $nodeFinder->find($node->stmts ?? [], static function (Node $child): bool {
            return $child instanceof Variable
                && is_string($child->name)
                && self::isVariableUse($child);
        });

        foreach ($usedVars as $var) {
            /** @var Variable $var Finder predicate restricts results to variable nodes. */
            if (is_string($var->name)) {
                $usedNames[$var->name] = true;
            }
        }

        return $usedNames;
    }

    /**
     * Detect whether the variable reference counts as a use; `unset($x)` is a placeholder, not a use.
     *
     * @return bool
     */
    private static function isVariableUse(Variable $variable): bool
    {
        $parent = $variable->getAttribute('parent');

        return !$parent instanceof Node\Stmt\Unset_
            || !in_array($variable, $parent->vars, true);
    }

    /**
     * Build the Finding for one unused parameter.
     *
     * @return Finding
     */
    private function findingForParameter(
        AnalysisUnit $analysisUnit,
        RuleDefinition $definition,
        ClassMethod|Function_ $node,
        string $name,
        Node\Param $param,
    ): Finding {
        $symbol = CyclomaticComplexityRule::resolveSymbol($node);

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('Parameter $%s in %s is never used.', $name, $symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $param->getStartLine(),
            column:      $this->startColumn($analysisUnit, $param),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Remove the parameter or use it in the method body.',
            metadata:    ['parameter' => $name],
        );
    }

    /**
     * Compute the 1-based column of the parameter's start position within its line, or null when unknown.
     *
     * @return int|null
     */
    private function startColumn(AnalysisUnit $analysisUnit, Node\Param $param): ?int
    {
        $startFilePosition = $param->getStartFilePos();

        if ($startFilePosition < 0) {
            return null;
        }

        $sourceBeforeParameter = substr($analysisUnit->source, 0, $startFilePosition);
        $lineStartPosition     = strrpos($sourceBeforeParameter, "\n");

        return $startFilePosition - ($lineStartPosition === false ? -1 : $lineStartPosition);
    }
}
