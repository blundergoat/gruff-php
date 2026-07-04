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
 * Flags a function or method parameter that is declared but never read in the body, so the user can drop
 * dead parameters - while carefully sparing signatures that an interface or parent contract fixes.
 *
 * Runs per file over every callable with a body and parameters. It skips abstract, magic, and
 * override/inheritDoc methods (whose signature is not the author's to change), then reports any plain
 * (non-promoted) parameter whose name never appears as a read in the body. Warning severity.
 */
final readonly class UnusedParameterRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused parameter findings.
     */
    public const ID = 'waste.unused-parameter';

    /**
     * Describes the unused-parameter rule for the registry and reports.
     *
     * @return RuleDefinition - the rule's static identity and defaults (id, name, pillar, tier, severity, confidence).
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
     * Reports each declared parameter never read across the unit's analysable callables.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per unused parameter across the unit's callables; empty when all are used.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $findings   = [];

        // Check each analysable callable in the file.
        foreach ($this->analysableNodes($analysisUnit) as $node) {
            array_push($findings, ...$this->findingsForNode($analysisUnit, $definition, $nodeFinder, $node));
        }

        return $findings;
    }

    /**
     * Lists the callables worth checking: those with both a body and plain parameters.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose AST is searched for parameter-bearing callables.
     *
     * @return list<ClassMethod|Function_> - callables that have both a body and parameters; excludes everything else.
     */
    private function analysableNodes(AnalysisUnit $analysisUnit): array
    {
        $foundNodes = NodeIndex::nodesOfAny($analysisUnit, [Function_::class, ClassMethod::class]);
        $nodes      = [];

        // Keep only callables whose parameters we can safely judge.
        foreach ($foundNodes as $node) {
            // Ignore anything that is not a function or method.
            if (!$node instanceof ClassMethod && !$node instanceof Function_) {
                continue;
            }

            // Skip methods whose signature is not the author's to change.
            if ($node instanceof ClassMethod && !$this->isAnalysableMethod($node)) {
                continue;
            }

            // Only a callable with both a body and parameters is worth checking.
            if (($node->stmts ?? []) !== [] && $node->params !== []) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Reports whether a method's parameters are the author's to change (not abstract, magic, or a fixed contract).
     *
     * @param ClassMethod $classMethod - Method declaration under inspection; its visibility and parent drive the decision.
     *
     * @return bool - true when the method body is in scope and not bound to an external interface contract.
     */
    private function isAnalysableMethod(ClassMethod $classMethod): bool
    {
        // Abstract and magic methods have no body to analyse and a signature we cannot change.
        if ($classMethod->isAbstract() || $this->isMagicContractMethod($classMethod)) {
            return false;
        }

        // A private method has no external caller, so an unused parameter is always the author's to remove.
        if ($classMethod->isPrivate()) {
            return true;
        }

        // Otherwise it is analysable only when no inherited contract forces the parameter to stay.
        return !$this->hasExternalMethodContract($classMethod);
    }

    /**
     * Reports whether a method is a magic method (`__toString`, `__get`, ...) whose parameter shape is fixed.
     *
     * @param ClassMethod $classMethod - Method whose name is matched against the PHP magic-method naming convention.
     *
     * @return bool - true when the name begins with `__` and is not `__construct`.
     */
    private function isMagicContractMethod(ClassMethod $classMethod): bool
    {
        $name = strtolower($classMethod->name->toString());

        // The `__` prefix marks an engine-defined method; `__construct` is exempt because callers own its parameters.
        return str_starts_with($name, '__') && $name !== '__construct';
    }

    /**
     * Reports whether a method inherits a fixed signature from an Override marker, inheritDoc, or a parent/interface.
     *
     * @param ClassMethod $classMethod - Method whose attributes, docblock, and enclosing type are checked for an inherited contract.
     *
     * @return bool - true when an Override attribute, inheritDoc marker, or `extends` / `implements` ancestor exists.
     */
    private function hasExternalMethodContract(ClassMethod $classMethod): bool
    {
        // An explicit Override or inheritDoc marker declares the signature is inherited and fixed.
        if ($this->hasOverrideAttribute($classMethod) || $this->hasInheritDoc($classMethod)) {
            return true;
        }

        $parent = $classMethod->getAttribute('parent');

        // A class that extends or implements may be honouring a parent signature we must not flag against.
        if ($parent instanceof Node\Stmt\Class_) {
            return $parent->extends !== null || $parent->implements !== [];
        }

        // Enums cannot extend, so only an implemented interface can impose a fixed signature.
        if ($parent instanceof Node\Stmt\Enum_) {
            return $parent->implements !== [];
        }

        // No marker and no extends/implements ancestor means the signature is entirely the author's own.
        return false;
    }

    /**
     * Reports whether a method carries a `#[\Override]` attribute.
     *
     * @param ClassMethod $classMethod - Method whose attribute groups are scanned for the `Override` marker.
     *
     * @return bool - true when at least one attribute group carries an `Override` marker, false otherwise.
     */
    private function hasOverrideAttribute(ClassMethod $classMethod): bool
    {
        // Scan each attribute group on the method.
        foreach ($classMethod->attrGroups as $attributeGroup) {
            // Check each attribute for the Override marker.
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
     * Reports whether a method's docblock carries `@inheritdoc` or `{@inheritdoc}`.
     *
     * @param ClassMethod $classMethod - Method whose attached docblock text is searched; absent docblock counts as no marker.
     *
     * @return bool - true when the docblock contains an inheritance marker; false when absent or no docblock exists.
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
     * Builds the unused-parameter findings for one callable by diffing its parameters against body usage.
     *
     * @param AnalysisUnit          $analysisUnit - Parsed unit supplying file path and source for finding locations.
     * @param RuleDefinition        $definition - Resolved rule metadata stamped onto each emitted finding.
     * @param NodeFinder            $nodeFinder - Shared finder reused across nodes to collect variable references.
     * @param ClassMethod|Function_ $node - Callable whose declared parameters are diffed against its body usage.
     *
     * @return list<Finding> - one finding per declared parameter not read in this callable's body; empty when all are used.
     */
    private function findingsForNode(
        AnalysisUnit          $analysisUnit,
        RuleDefinition        $definition,
        NodeFinder            $nodeFinder,
        ClassMethod|Function_ $node,
    ): array {
        $usedNames = $this->usedVariableNames($node, $nodeFinder);
        $findings  = [];

        // Report each parameter whose name is never read.
        foreach ($this->parameterNames($node) as $name => $param) {
            // A parameter absent from the used-names set is unused.
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
     * Indexes a callable's plain parameters by name, skipping promoted-property params.
     *
     * @param ClassMethod|Function_ $node - Function-like declaration whose plain parameters are indexed.
     *
     * @return array<string, \PhpParser\Node\Param> - plain parameters keyed by name (no leading `$`); promoted-property params omitted.
     */
    private function parameterNames(ClassMethod|Function_ $node): array
    {
        $paramNames = [];

        // Index each plain parameter by name.
        foreach ($node->params as $param) {
            // A promoted param becomes a property, so it is out of scope here.
            if ($param->flags !== 0) {
                continue;
            }

            // Only a plain `$name` parameter has a name to track.
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $paramNames[$param->var->name] = $param;
            }
        }

        return $paramNames;
    }

    /**
     * Collects the set of variable names actually read in a callable's body.
     *
     * @param ClassMethod|Function_ $node - Callable whose statement list is walked for variable reads.
     * @param NodeFinder            $nodeFinder - Finder used to traverse the body; `unset()` targets are excluded as non-uses.
     *
     * @return array<string, true> - set of variable names read in the body, keyed for O(1) membership tests.
     */
    private function usedVariableNames(ClassMethod|Function_ $node, NodeFinder $nodeFinder): array
    {
        $usedNames = [];
        $usedVars  = $nodeFinder->find($node->stmts ?? [], static function (Node $child): bool {
            return $child instanceof Variable
                   && is_string($child->name)
                   && self::isVariableUse($child);
        });

        // Record each variable name the body reads.
        foreach ($usedVars as $var) {
            /** @var Variable $var Finder predicate restricts results to variable nodes. */
            // Keep only string-named variables, skipping dynamic ${...} names.
            if (is_string($var->name)) {
                $usedNames[$var->name] = true;
            }
        }

        return $usedNames;
    }

    /**
     * Reports whether a variable reference is a real read, treating an `unset($x)` operand as not a use.
     *
     * @param Variable $variable - Variable node found in the body; its parent decides whether it reads or merely clears the slot.
     *
     * @return bool - true when the reference reads the variable; false only when it is an operand of `unset()`.
     */
    private static function isVariableUse(Variable $variable): bool
    {
        $parent = $variable->getAttribute('parent');

        // Treat as a use unless this exact node is an operand of `unset()`, which clears rather than reads it.
        return !$parent instanceof Node\Stmt\Unset_
               || !in_array($variable, $parent->vars, true);
    }

    /**
     * Builds the finding for one unused parameter, pointing at the parameter's own source position.
     *
     * @param AnalysisUnit          $analysisUnit - Parsed unit supplying the display path reported in the finding.
     * @param RuleDefinition        $definition - Resolved rule metadata copied into the finding's id, severity, and pillar.
     * @param ClassMethod|Function_ $node - Enclosing callable, resolved to a human-readable symbol for the message.
     * @param string                $name - Parameter name without the leading `$`, used in the message and metadata.
     * @param Node\Param            $param - Parameter node giving the source position the finding points at.
     *
     * @return Finding - the unused-parameter finding pointing at the parameter's own source position.
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
     * Computes the 1-based column of a parameter's start within its line, or null when the position is unknown.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose raw source is sliced to locate the line start.
     * @param Node\Param   $param - Parameter node; a negative recorded start offset yields null instead of a column.
     *
     * @return int|null - 1-based column of the parameter's start within its line; null when the start offset is unknown.
     */
    private function startColumn(AnalysisUnit $analysisUnit, Node\Param $param): ?int
    {
        $startFilePosition = $param->getStartFilePos();

        // The parser records -1 when position tracking is off; report an unknown column rather than guess.
        if ($startFilePosition < 0) {
            return null;
        }

        $sourceBeforeParameter = substr($analysisUnit->source, 0, $startFilePosition);
        $lineStartPosition     = strrpos($sourceBeforeParameter, "\n");

        // Offset from the preceding newline gives a 1-based column; a first-line param has no newline, so treat it as -1.
        return $startFilePosition - ($lineStartPosition === false ? -1 : $lineStartPosition);
    }
}
