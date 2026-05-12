<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

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
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

final class DangerousFunctionCallRule implements RuleInterface
{
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
            id: self::ID,
            name: 'Dangerous function calls',
            pillar: Pillar::Security,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
        );
    }

    /**
     * Find dynamic execution, eval, assert-string, and dangerous shell calls.
     *
     * @return list<Finding> Findings for dangerous execution patterns.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];
        $callableParameters = $this->callableParameterNames($unit->statements, $finder);
        $callableProperties = $this->callablePropertyNames($unit->statements, $finder);

        foreach ($finder->findInstanceOf($unit->statements, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if ($name === null) {
                if (!$call->name instanceof Node\Name && !$this->isKnownCallableInvocation($call->name, $callableParameters, $callableProperties)) {
                    $findings[] = $this->finding($unit, $call, 'dynamic function call');
                }

                continue;
            }

            if (in_array($name, self::DANGEROUS_FUNCTIONS, true)) {
                $findings[] = $this->finding($unit, $call, $name);
            }

            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            if ($name === 'assert' && $firstArg !== null && SecurityNodeHelper::isStringLiteral($firstArg)) {
                $findings[] = $this->finding($unit, $call, 'assert string evaluation');
            }
        }

        foreach ($finder->findInstanceOf($unit->statements, Expr\Eval_::class) as $eval) {
            $findings[] = $this->finding($unit, $eval, 'eval');
        }

        return $findings;
    }

    /**
     * @param list<Node\Stmt> $statements
     * @return array<string, true>
     */
    private function callableParameterNames(array $statements, NodeFinder $finder): array
    {
        $names = [];
        $functions = $finder->find($statements, static fn (Node $node): bool => $node instanceof Function_ || $node instanceof ClassMethod);

        foreach ($functions as $function) {
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
     * @param list<Node\Stmt> $statements
     * @return array<string, true>
     */
    private function callablePropertyNames(array $statements, NodeFinder $finder): array
    {
        $names = [];

        foreach ($finder->findInstanceOf($statements, Property::class) as $property) {
            if (!$this->isCallableType($property->type)) {
                continue;
            }

            foreach ($property->props as $prop) {
                $names[$prop->name->toString()] = true;
            }
        }

        foreach ($finder->findInstanceOf($statements, Class_::class) as $class) {
            foreach ($class->getMethods() as $method) {
                foreach ($method->params as $param) {
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
     *
     * @return bool True when a dynamic call targets a known callable slot.
     */
    private function isKnownCallableInvocation(Node $name, array $callableParameters, array $callableProperties): bool
    {
        if ($name instanceof Expr\Variable && is_string($name->name)) {
            return isset($callableParameters[$name->name]);
        }

        if ($name instanceof Expr\PropertyFetch && $name->name instanceof Identifier) {
            return isset($callableProperties[$name->name->toString()]);
        }

        return false;
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

        return false;
    }

    /**
     * Build the security finding for a dangerous execution node.
     *
     * @return Finding Security finding.
     */
    private function finding(AnalysisUnit $unit, Node $node, string $function): Finding
    {
        return new Finding(
            ruleId: self::ID,
            message: sprintf('Dangerous PHP execution pattern detected: %s.', $function),
            filePath: $unit->file->displayPath,
            line: $node->getStartLine(),
            severity: Severity::Warning,
            pillar: Pillar::Security,
            tier: RuleTier::V01,
            confidence: Confidence::Medium,
            remediation: 'Replace direct execution with a constrained wrapper, strict allow-lists, or a non-shell API.',
            metadata: [
                'function' => $function,
            ],
        );
    }
}
