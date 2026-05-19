<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

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
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;

/**
 * Detects negative boolean flags that create double-negative call sites.
 *
 * Overlap deferral order is centralised in RuleRegistry:
 * class-file-mismatch > confusing-name > negative-boolean > boolean-prefix >
 * parameter-type-name > identifier-quality > hungarian-notation >
 * suffix-hungarian > short-variable > abbreviation-allowlist.
 */
final readonly class NegativeBooleanRule implements RuleInterface
{
    /** Stable identifier for the negative-boolean rule. */
    public const ID = 'naming.negative-boolean';

    /** Negative prefixes that make boolean flags harder to read. */
    private const NEGATIVE_PREFIXES = ['no', 'not', 'non', 'disable', 'skip', 'dont', 'cant', 'wont'];

    /**
     * Describe the negative boolean rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Negative boolean flag',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  ['cliMirrorAllowlist' => []],
            description:     'Flags typed bool properties and parameters named as negative flags unless they explicitly mirror a CLI flag.',
        );
    }

    /**
     * Find bool properties and parameters that use negative flag names.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for CLI mirror allowlist.
     * @return list<Finding> Findings for negative boolean flags.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $allowlist  = $ruleContext->settingsFor($definition)->stringListOption('cliMirrorAllowlist');
        $nodeFinder = new NodeFinder();
        $findings   = [];

        foreach ($nodeFinder->findInstanceOf($analysisUnit->statements, Property::class) as $property) {
            if (!$this->isBoolType($property->type)) {
                continue;
            }

            foreach ($property->props as $prop) {
                $finding = $this->propertyFinding(
                    definition:       $definition,
                    analysisUnit:             $analysisUnit,
                    propertyProperty: $prop,
                    allowlist:        $allowlist,
                );
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        foreach ((new FunctionLikeScopeWalker())->scopes($analysisUnit->statements) as $scope) {
            foreach ($scope->node->params as $param) {
                $finding = $this->parameterFinding(
                    definition: $definition,
                    analysisUnit:       $analysisUnit,
                    scope:      $scope,
                    param:      $param,
                    allowlist:  $allowlist,
                );
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $allowlist
     * @return Finding|null Finding for a negative boolean property.
     */
    private function propertyFinding(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        PropertyProperty $propertyProperty,
        array $allowlist,
    ): ?Finding {
        $name         = $propertyProperty->name->toString();
        $prefix       = $this->negativePrefix($name);
        $allowlistKey = $this->propertyAllowlistKey($propertyProperty);

        if ($prefix === null || ($allowlistKey !== null && in_array($allowlistKey, $allowlist, true))) {
            return null;
        }

        return $this->finding(
            definition:   $definition,
            analysisUnit:         $analysisUnit,
            node:         $propertyProperty,
            kind:         'property',
            name:         $name,
            prefix:       $prefix,
            symbol:       '$' . $name,
            allowlistKey: $allowlistKey,
        );
    }

    /**
     * @param list<string> $allowlist
     * @return Finding|null Finding for a negative boolean parameter.
     */
    private function parameterFinding(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        Param $param,
        array $allowlist,
    ): ?Finding {
        if (!$this->isBoolType($param->type) || !$param->var instanceof Variable || !is_string($param->var->name)) {
            return null;
        }

        $name         = $param->var->name;
        $prefix       = $this->negativePrefix($name);
        $allowlistKey = $this->parameterAllowlistKey($scope, $param);

        if ($prefix === null || ($allowlistKey !== null && in_array($allowlistKey, $allowlist, true))) {
            return null;
        }

        return $this->finding(
            definition:   $definition,
            analysisUnit:         $analysisUnit,
            node:         $param,
            kind:         $param->flags === 0 ? 'parameter' : 'property',
            name:         $name,
            prefix:       $prefix,
            symbol:       $this->symbol($scope),
            allowlistKey: $allowlistKey,
        );
    }

    /**
     * Build a negative boolean finding.
     *
     * @return Finding Finding for a negative boolean identifier.
     */
    private function finding(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        Node $node,
        string $kind,
        string $name,
        string $prefix,
        ?string $symbol,
        ?string $allowlistKey,
    ): Finding {
        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s "$%s" is a negative boolean flag.', ucfirst($kind), $name),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Rename to a positive boolean or add a precise cliMirrorAllowlist entry when this directly mirrors a CLI flag.',
            metadata:    [
                'identifierKind' => $kind,
                'identifierName' => $name,
                'prefix' => $prefix,
                'allowlistKey' => $allowlistKey,
            ],
        );
    }

    /**
     * Detect the configured negative prefix at a camel-case word boundary.
     *
     * @return string|null Matched prefix, or null when the name is acceptable.
     */
    private function negativePrefix(string $name): ?string
    {
        foreach (self::NEGATIVE_PREFIXES as $prefix) {
            if (!str_starts_with($name, $prefix) || strlen($name) <= strlen($prefix)) {
                continue;
            }

            $nextChar = $name[strlen($prefix)];
            if ($nextChar >= 'A' && $nextChar <= 'Z') {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * Build the allowlist key for a property when its declaring class is known.
     *
     * @return string|null Fully qualified property key.
     */
    private function propertyAllowlistKey(PropertyProperty $propertyProperty): ?string
    {
        $class = $this->enclosingClassLike($propertyProperty);
        $fqn   = $class instanceof ClassLike ? $this->classLikeFqn($class) : null;

        return $fqn === null ? null : sprintf('%s::%s', $fqn, $propertyProperty->name->toString());
    }

    /**
     * Build the allowlist key for a parameter or promoted property.
     *
     * @return string|null Fully qualified parameter key.
     */
    private function parameterAllowlistKey(FunctionLikeScope $scope, Param $param): ?string
    {
        if (!$param->var instanceof Variable || !is_string($param->var->name)) {
            return null;
        }

        $class = $this->enclosingClassLike($scope->node);
        $fqn   = $class instanceof ClassLike ? $this->classLikeFqn($class) : null;
        if ($fqn === null) {
            return null;
        }

        if ($param->flags !== 0) {
            return sprintf('%s::%s', $fqn, $param->var->name);
        }

        if (!$scope->node instanceof ClassMethod) {
            return null;
        }

        return sprintf('%s::%s::%s', $fqn, $scope->node->name->toString(), $param->var->name);
    }

    /**
     * Resolve a class-like node to its namespace-qualified name.
     *
     * @return string|null Fully qualified class-like name.
     */
    private function classLikeFqn(ClassLike $class): ?string
    {
        if ($class->name === null) {
            return null;
        }

        $namespace = $this->enclosingNamespace($class);
        $className = $class->name->toString();

        return $namespace instanceof Namespace_ && $namespace->name instanceof Name
            ? $namespace->name->toString() . '\\' . $className
            : $className;
    }

    /**
     * Walk parent attributes to find the enclosing class-like declaration.
     *
     * @return ClassLike|null Enclosing class, interface, trait, or enum.
     */
    private function enclosingClassLike(Node $node): ?ClassLike
    {
        $parent = $node->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof ClassLike) {
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * Walk parent attributes to find the enclosing namespace.
     *
     * @return Namespace_|null Enclosing namespace node.
     */
    private function enclosingNamespace(Node $node): ?Namespace_
    {
        $parent = $node->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof Namespace_) {
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * Check whether a declaration type is bool or nullable bool.
     *
     * @return bool True when the type resolves to bool, including ?bool and bool|null.
     */
    private function isBoolType(?Node $type): bool
    {
        if ($type instanceof NullableType) {
            return $this->isBoolType($type->type);
        }

        if ($type instanceof Identifier) {
            return $type->toLowerString() === 'bool';
        }

        if ($type instanceof Name) {
            return strtolower($type->toString()) === 'bool';
        }

        if ($type instanceof UnionType) {
            $nonNull = array_values(array_filter(
                $type->types,
                static fn (Node $node): bool => !($node instanceof Identifier && $node->toLowerString() === 'null'),
            ));

            return count($nonNull) === 1 && $this->isBoolType($nonNull[0]);
        }

        return false;
    }

    /**
     * Resolve the human-readable symbol for a function-like scope.
     *
     * @return string Named callable symbol or synthetic closure/arrow label.
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }
}
