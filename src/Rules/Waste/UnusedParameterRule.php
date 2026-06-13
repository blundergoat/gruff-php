<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Waste;

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
     * @return RuleDefinition - the rule's static identity and defaults (id, name, pillar, tier, severity, confidence)
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
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per unused parameter across the unit's callables; empty when all are used
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
     * List functions and methods whose parameters can be checked for use.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose AST is searched for parameter-bearing callables.
     *
     * @return list<ClassMethod|Function_> - callables that have both a body and parameters; excludes everything else
     */
    private function analysableNodes(AnalysisUnit $analysisUnit): array
    {
        $foundNodes = NodeIndex::nodesOfAny($analysisUnit, [Function_::class, ClassMethod::class]);
        $nodes      = [];

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
     * @param ClassMethod $classMethod - Method declaration under inspection; its visibility and parent drive the decision.
     *
     * @return bool - true when the method body is in scope and not bound to an external interface contract
     */
    private function isAnalysableMethod(ClassMethod $classMethod): bool
    {
        if ($classMethod->isAbstract() || $this->isMagicContractMethod($classMethod)) {
            // Abstract and magic methods have no body to analyse and a signature we cannot change.
            return false;
        }

        if ($classMethod->isPrivate()) {
            // A private method has no external caller, so an unused parameter is always the author's to remove.
            return true;
        }

        // Otherwise it is analysable only when no inherited contract forces the parameter to stay.
        return !$this->hasExternalMethodContract($classMethod);
    }

    /**
     * Detect whether the method is a magic / contract method (`__toString`, `__get`, etc.) where parameter shape is fixed.
     *
     * @param ClassMethod $classMethod - Method whose name is matched against the PHP magic-method naming convention.
     *
     * @return bool - true when the name begins with `__` and is not `__construct`
     */
    private function isMagicContractMethod(ClassMethod $classMethod): bool
    {
        $name = strtolower($classMethod->name->toString());

        // The `__` prefix marks an engine-defined method; `__construct` is exempt because callers own its parameters.
        return str_starts_with($name, '__') && $name !== '__construct';
    }

    /**
     * Detect whether the method overrides or implements an external contract whose signature is mandatory.
     *
     * @param ClassMethod $classMethod - Method whose attributes, docblock, and enclosing type are checked for an inherited contract.
     *
     * @return bool - true when an Override attribute, inheritDoc marker, or `extends` / `implements` ancestor exists
     */
    private function hasExternalMethodContract(ClassMethod $classMethod): bool
    {
        if ($this->hasOverrideAttribute($classMethod) || $this->hasInheritDoc($classMethod)) {
            // An explicit Override or inheritDoc marker declares the signature is inherited and fixed.
            return true;
        }

        $parent = $classMethod->getAttribute('parent');

        if ($parent instanceof Node\Stmt\Class_) {
            // A class that extends or implements may be honouring a parent signature we must not flag against.
            return $parent->extends !== null || $parent->implements !== [];
        }

        if ($parent instanceof Node\Stmt\Enum_) {
            // Enums cannot extend, so only an implemented interface can impose a fixed signature.
            return $parent->implements !== [];
        }

        // No marker and no extends/implements ancestor means the signature is entirely the author's own.
        return false;
    }

    /**
     * Detect whether the method carries a `#[\Override]` attribute.
     *
     * @param ClassMethod $classMethod - Method whose attribute groups are scanned for the `Override` marker.
     *
     * @return bool - true when at least one attribute group carries an `Override` marker, false otherwise
     */
    private function hasOverrideAttribute(ClassMethod $classMethod): bool
    {
        foreach ($classMethod->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if (strtolower($attribute->name->getLast()) === 'override') {
                    // Short-circuit on the first `Override` attribute; one is enough to bind the signature.
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect whether the method's docblock contains `@inheritdoc` or `{@inheritdoc}`.
     *
     * @param ClassMethod $classMethod - Method whose attached docblock text is searched; absent docblock counts as no marker.
     *
     * @return bool - true when the docblock contains an inheritance marker; false when absent or no docblock exists
     */
    private function hasInheritDoc(ClassMethod $classMethod): bool
    {
        $docComment = $classMethod->getDocComment();

        // A method with no docblock cannot inherit one, so treat the missing-docblock case as no marker.
        return $docComment !== null
               // Match both block `@inheritdoc` and inline `{@inheritdoc}` inheritance markers.
               && preg_match('/\\{?@inheritdoc\\b\\}?/i', $docComment->getText()) === 1;
    }

    /**
     * Build unused-parameter findings for one function or method.
     *
     * @param AnalysisUnit          $analysisUnit - Parsed unit supplying file path and source for finding locations.
     * @param RuleDefinition        $definition - Resolved rule metadata stamped onto each emitted finding.
     * @param NodeFinder            $nodeFinder - Shared finder reused across nodes to collect variable references.
     * @param ClassMethod|Function_ $node - Callable whose declared parameters are diffed against its body usage.
     *
     * @return list<Finding> - one finding per declared parameter not read in this callable's body; empty when all are used
     */
    private function findingsForNode(
        AnalysisUnit          $analysisUnit,
        RuleDefinition        $definition,
        NodeFinder            $nodeFinder,
        ClassMethod|Function_ $node,
    ): array {
        $usedNames = $this->usedVariableNames($node, $nodeFinder);
        $findings  = [];

        foreach ($this->parameterNames($node) as $name => $param) {
            if (!isset($usedNames[$name])) {
                $findings[] = $this->findingForParameter(
                    analysisUnit: $analysisUnit,
                    definition:   $definition,
                    node:         $node,
                    name:         $name,
                    param:        $param,
                );
            }
        }

        return $findings;
    }

    /**
     * Index parameters declared by a function or method.
     *
     * @param ClassMethod|Function_ $node - Function-like declaration whose plain parameters are indexed.
     *
     * @return array<string, \PhpParser\Node\Param> - plain parameters keyed by name (no leading `$`); promoted-property params omitted
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
     * Collect variable names referenced inside a function or method body.
     *
     * @param ClassMethod|Function_ $node - Callable whose statement list is walked for variable reads.
     * @param NodeFinder            $nodeFinder - Finder used to traverse the body; `unset()` targets are excluded as non-uses.
     *
     * @return array<string, true> - set of variable names read in the body, keyed for O(1) membership tests
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
     * @param Variable $variable - Variable node found in the body; its parent decides whether it reads or merely clears the slot.
     *
     * @return bool - true when the reference reads the variable; false only when it is an operand of `unset()`
     */
    private static function isVariableUse(Variable $variable): bool
    {
        $parent = $variable->getAttribute('parent');

        // Treat as a use unless this exact node is an operand of `unset()`, which clears rather than reads it.
        return !$parent instanceof Node\Stmt\Unset_
               || !in_array($variable, $parent->vars, true);
    }

    /**
     * Build the Finding for one unused parameter.
     *
     * @param AnalysisUnit          $analysisUnit - Parsed unit supplying the display path reported in the finding.
     * @param RuleDefinition        $definition - Resolved rule metadata copied into the finding's id, severity, and pillar.
     * @param ClassMethod|Function_ $node - Enclosing callable, resolved to a human-readable symbol for the message.
     * @param string                $name - Parameter name without the leading `$`, used in the message and metadata.
     * @param Node\Param            $param - Parameter node giving the source position the finding points at.
     *
     * @return Finding - the unused-parameter finding pointing at the parameter's own source position
     */
    private function findingForParameter(
        AnalysisUnit          $analysisUnit,
        RuleDefinition        $definition,
        ClassMethod|Function_ $node,
        string                $name,
        Node\Param            $param,
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
     * @param AnalysisUnit $analysisUnit - Parsed unit whose raw source is sliced to locate the line start.
     * @param Node\Param   $param - Parameter node; a negative recorded start offset yields null instead of a column.
     *
     * @return int|null - 1-based column of the parameter's start within its line; null when the start offset is unknown
     */
    private function startColumn(AnalysisUnit $analysisUnit, Node\Param $param): ?int
    {
        $startFilePosition = $param->getStartFilePos();

        if ($startFilePosition < 0) {
            // The parser records -1 when position tracking is off; report an unknown column rather than guess.
            return null;
        }

        $sourceBeforeParameter = substr($analysisUnit->source, 0, $startFilePosition);
        $lineStartPosition     = strrpos($sourceBeforeParameter, "\n");

        // Offset from the preceding newline gives a 1-based column; a first-line param has no newline, so treat it as -1.
        return $startFilePosition - ($lineStartPosition === false ? -1 : $lineStartPosition);
    }
}
