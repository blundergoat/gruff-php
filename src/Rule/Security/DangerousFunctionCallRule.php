<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;

/**
 * Detects calls to execution and evaluation functions with high security risk.
 */
final class DangerousFunctionCallRule implements RuleInterface
{
    /**
     * Stable rule identifier for dangerous function call findings.
     */
    public const ID = 'security.dangerous-function-call';

    /**
     * @var list<string>
     */
    private const DANGEROUS_FUNCTIONS = [
        'exec',
        'passthru',
        'popen',
        'proc_open',
        'shell_exec',
        'system',
    ];

    /**
     * Describe the dangerous function call rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Dangerous function calls',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find dynamic execution, eval, assert-string, and dangerous shell calls.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for dangerous execution patterns.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings           = [];
        $callableParameters = $this->callableParameterNames($analysisUnit);
        $callableProperties = $this->callablePropertyNames($analysisUnit);
        $callableLocals     = array_replace(
            $this->callableLocalVariableNames($analysisUnit),
            $this->callableForeachVariableNames($analysisUnit),
        );

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if ($name === null) {
                if (!$call->name instanceof Node\Name && !$this->isKnownCallableInvocation($call->name, $callableParameters, $callableProperties, $callableLocals)) {
                    $findings[] = $this->finding($analysisUnit, $call, 'dynamic function call');
                }

                continue;
            }

            if (in_array($name, self::DANGEROUS_FUNCTIONS, true)) {
                $findings[] = $this->finding($analysisUnit, $call, $name);
            }

            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            if ($name === 'assert' && $firstArg !== null && SecurityNodeHelper::isStringLiteral($firstArg)) {
                $findings[] = $this->finding($analysisUnit, $call, 'assert string evaluation');
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Eval_::class) as $eval) {
            $findings[] = $this->finding($analysisUnit, $eval, 'eval');
        }

        return $findings;
    }

    /**
     * @return array<string, true>
     */
    private function callableLocalVariableNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assignment) {
            if (
                !$assignment->var instanceof Expr\Variable
                || !is_string($assignment->var->name)
                || !$this->isCallableExpression($assignment->expr)
            ) {
                continue;
            }

            $names[$assignment->var->name] = true;
        }

        return $names;
    }

    /**
     * @return array<string, true>
     */
    private function callableForeachVariableNames(AnalysisUnit $analysisUnit): array
    {
        $names                         = [];
        $callableCollectionProperties  = $this->callableCollectionPropertyNames($analysisUnit);

        foreach (NodeIndex::nodesOf($analysisUnit, Foreach_::class) as $foreach) {
            if (!$foreach->valueVar instanceof Expr\Variable || !is_string($foreach->valueVar->name)) {
                continue;
            }

            if (!$this->isCallableCollectionExpression($foreach->expr, $callableCollectionProperties)) {
                continue;
            }

            $names[$foreach->valueVar->name] = true;
        }

        return $names;
    }

    /**
     * @return array<string, true>
     */
    private function callableCollectionPropertyNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assignment) {
            if (
                !$assignment->var instanceof Expr\ArrayDimFetch
                || !$assignment->var->var instanceof Expr\PropertyFetch
                || !$assignment->var->var->name instanceof Identifier
                || !$this->isCallableExpression($assignment->expr)
            ) {
                continue;
            }

            $names[$assignment->var->var->name->toString()] = true;
        }

        return $names;
    }

    /**
     * @return array<string, true>
     */
    private function callableParameterNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        foreach (NodeIndex::nodesOfAny($analysisUnit, [Function_::class, ClassMethod::class]) as $function) {
            if (!$function instanceof Function_ && !$function instanceof ClassMethod) {
                continue;
            }

            foreach ($function->params as $param) {
                if (!$param->var instanceof Expr\Variable || !is_string($param->var->name) || !$this->isCallableType($param->type)) {
                    continue;
                }

                $names[$param->var->name] = true;
            }
        }

        return $names;
    }

    /**
     * @return array<string, true>
     */
    private function callablePropertyNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            if (!$this->isCallableType($property->type) && !$this->hasCallableDocblock($property)) {
                continue;
            }

            foreach ($property->props as $prop) {
                $names[$prop->name->toString()] = true;
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Class_::class) as $class) {
            foreach ($class->getMethods() as $classMethod) {
                foreach ($classMethod->params as $param) {
                    if (
                        $param->flags === 0
                        || !$param->var instanceof Expr\Variable
                        || !is_string($param->var->name)
                        || !$this->isCallableType($param->type)
                    ) {
                        continue;
                    }

                    $names[$param->var->name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * @param array<string, true> $callableParameters
     * @param array<string, true> $callableProperties
     * @param array<string, true> $callableLocals
     *
     * @return bool True when a dynamic call targets a known callable slot.
     */
    private function isKnownCallableInvocation(Node $name, array $callableParameters, array $callableProperties, array $callableLocals): bool
    {
        if ($name instanceof Expr\Variable && is_string($name->name)) {
            return isset($callableParameters[$name->name]) || isset($callableLocals[$name->name]);
        }

        if ($name instanceof Expr\PropertyFetch && $name->name instanceof Identifier) {
            return isset($callableProperties[$name->name->toString()]);
        }

        return false;
    }

    /**
     * Detect PHPDoc callable property annotations.
     *
     * @return bool True when the property docblock declares a callable value.
     */
    private function hasCallableDocblock(Property $property): bool
    {
        $docComment = $property->getDocComment();
        if ($docComment === null) {
            return false;
        }

        return preg_match('/@var\s+[^\n]*(?:callable|Closure)\b/i', $docComment->getText()) === 1;
    }

    /**
     * Detect expressions whose value is explicitly a callable.
     *
     * @return bool True when the expression creates a callable value.
     */
    private function isCallableExpression(Expr $expr): bool
    {
        return $expr instanceof Expr\Closure
            || $expr instanceof Expr\ArrowFunction
            || $this->isStaticCallableArray($expr);
    }

    /**
     * Detect `[$receiver, 'method']` and `[ClassName::class, 'method']` callables.
     *
     * @return bool True when the array is shaped like an explicit callable.
     */
    private function isStaticCallableArray(Expr $expr): bool
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            return false;
        }

        $methodItem = $expr->items[1] ?? null;

        return $methodItem !== null
            && !$methodItem->unpack
            && $methodItem->value instanceof Scalar\String_;
    }

    /**
     * Detect foreach over properties known to contain callable values.
     *
     * @param array<string, true> $callableCollectionProperties
     * @return bool True when the foreach expression is a callable collection.
     */
    private function isCallableCollectionExpression(Expr $expr, array $callableCollectionProperties): bool
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->name instanceof Identifier) {
            return false;
        }

        return isset($callableCollectionProperties[$expr->name->toString()]);
    }

    /**
     * Detect callable-like parameter or property types.
     *
     * @return bool True when the type permits callable invocation.
     */
    private function isCallableType(?Node $type): bool
    {
        if ($type instanceof Identifier) {
            return strtolower($type->toString()) === 'callable';
        }

        if ($type instanceof Name) {
            $shortName = strtolower($type->getLast());

            return $shortName === 'closure' || str_ends_with($shortName, 'callable');
        }

        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $innerType) {
                if ($this->isCallableType($innerType)) {
                    return true;
                }
            }
        }

        if ($type instanceof Node\NullableType) {
            return $this->isCallableType($type->type);
        }

        return false;
    }

    /**
     * Build the security finding for a dangerous execution node.
     *
     * @return Finding Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $function): Finding
    {
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('Dangerous PHP execution pattern detected: %s.', $function),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Replace direct execution with a constrained wrapper, strict allow-lists, or a non-shell API.',
            metadata:    [
                'function' => $function,
            ],
        );
    }
}
