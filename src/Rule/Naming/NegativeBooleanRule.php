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
use GruffPhp\Rule\NodeIndex;
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

/**
 * Detects negative boolean flags that create double-negative call sites.
 *
 * Overlap deferral order is centralised in RuleRegistry:
 * class-file-mismatch > confusing-name > negative-boolean > boolean-prefix >
 * identifier-quality > hungarian-notation > suffix-hungarian > short-variable >
 * abbreviation-allowlist.
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
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory by default: a negative flag is a readability smell, not a correctness defect.
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
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for CLI mirror allowlist.
     *
     * @return list<Finding> - Findings for negative boolean flags.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $allowlist  = $ruleContext->settingsFor($definition)->stringListOption('cliMirrorAllowlist');
        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            if (!$this->isBoolType($property->type)) {
                continue;
            }

            foreach ($property->props as $prop) {
                $finding = $this->propertyFinding(
                    definition:       $definition,
                    analysisUnit:     $analysisUnit,
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
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    scope:        $scope,
                    param:        $param,
                    allowlist:    $allowlist,
                );
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param RuleDefinition   $definition - Rule metadata threaded into any emitted finding.
     * @param AnalysisUnit     $analysisUnit - Parsed unit supplying the display path and line for the finding.
     * @param PropertyProperty $propertyProperty - Single declared property whose name is tested for a negative prefix.
     * @param list<string>     $allowlist - Fully qualified property keys that opt out as deliberate CLI mirrors.
     *
     * @return Finding|null - Finding for a negative boolean property.
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
            // No negative prefix, or an explicit allowlist opt-out: this property is acceptable.
            return null;
        }

        // The property is a typed bool with a negative prefix and no opt-out, so report it.
        return $this->finding(
            definition:   $definition,
            analysisUnit: $analysisUnit,
            node:         $propertyProperty,
            kind:         'property',
            name:         $name,
            prefix:       $prefix,
            symbol:       '$' . $name,
            allowlistKey: $allowlistKey,
        );
    }

    /**
     * @param RuleDefinition    $definition - Rule metadata threaded into any emitted finding.
     * @param AnalysisUnit      $analysisUnit - Parsed unit supplying the display path and line for the finding.
     * @param FunctionLikeScope $scope - Enclosing function-like scope, used to build the symbol and allowlist key.
     * @param Param             $param - Single parameter (possibly a promoted property) whose name is tested.
     * @param list<string>      $allowlist - Fully qualified parameter keys that opt out as deliberate CLI mirrors.
     *
     * @return Finding|null - Finding for a negative boolean parameter.
     */
    private function parameterFinding(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        Param $param,
        array $allowlist,
    ): ?Finding {
        if (!$this->isBoolType($param->type) || !$param->var instanceof Variable || !is_string($param->var->name)) {
            // Only typed-bool parameters with a plain variable name are in scope; skip everything else.
            return null;
        }

        $name         = $param->var->name;
        $prefix       = $this->negativePrefix($name);
        $allowlistKey = $this->parameterAllowlistKey($scope, $param);

        if ($prefix === null || ($allowlistKey !== null && in_array($allowlistKey, $allowlist, true))) {
            // No negative prefix, or an explicit allowlist opt-out: this parameter is acceptable.
            return null;
        }

        // Non-zero flags mark a promoted constructor property, so classify it as a property not a parameter.
        return $this->finding(
            definition:   $definition,
            analysisUnit: $analysisUnit,
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
     * @param RuleDefinition $definition - Source of the rule id, severity, pillar, tier, and confidence on the finding.
     * @param AnalysisUnit   $analysisUnit - Parsed unit supplying the display path reported as the finding location.
     * @param Node           $node - Declaration node whose start line locates the finding.
     * @param string         $kind - Either 'property' or 'parameter'; shapes the message and metadata.
     * @param string         $name - Offending identifier without its leading sigil.
     * @param string         $prefix - Matched negative prefix, recorded in metadata for downstream grouping.
     * @param string|null    $symbol - Enclosing callable symbol, or null for a class property outside any method.
     * @param string|null    $allowlistKey - Key a project would add to cliMirrorAllowlist to suppress this finding.
     *
     * @return Finding - Finding for a negative boolean identifier.
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
        // Carry the prefix and allowlist key in metadata so reporters can group and suggest the precise opt-out.
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
     * @param string $name - Identifier to test, without its leading sigil.
     *
     * @return string|null - Matched prefix, or null when the name is acceptable.
     */
    private function negativePrefix(string $name): ?string
    {
        foreach (self::NEGATIVE_PREFIXES as $prefix) {
            if (!str_starts_with($name, $prefix) || strlen($name) <= strlen($prefix)) {
                continue;
            }

            $nextChar = $name[strlen($prefix)];
            if ($nextChar >= 'A' && $nextChar <= 'Z') {
                // Uppercase next char proves a word boundary, so "noCache" matches but "north" does not.
                return $prefix;
            }
        }

        // No prefix sits on a camel-case boundary, so the name is not a negative flag.
        return null;
    }

    /**
     * Build the allowlist key for a property when its declaring class is known.
     *
     * @param PropertyProperty $propertyProperty - Declared property whose fully qualified key is being built.
     *
     * @return string|null - Fully qualified property key.
     */
    private function propertyAllowlistKey(PropertyProperty $propertyProperty): ?string
    {
        $class = $this->enclosingClassLike($propertyProperty);
        $fqn   = $class instanceof ClassLike ? $this->classLikeFqn($class) : null;

        // Without a resolvable class the key would be ambiguous, so yield null rather than a partial key.
        return $fqn === null ? null : sprintf('%s::%s', $fqn, $propertyProperty->name->toString());
    }

    /**
     * Build the allowlist key for a parameter or promoted property.
     *
     * @param FunctionLikeScope $scope - Enclosing scope whose node resolves the declaring class and method name.
     * @param Param             $param - Parameter whose name and promotion flags shape the key.
     *
     * @return string|null - Fully qualified parameter key.
     */
    private function parameterAllowlistKey(FunctionLikeScope $scope, Param $param): ?string
    {
        if (!$param->var instanceof Variable || !is_string($param->var->name)) {
            // A variadic or destructured param has no plain name to key on, so it cannot be allowlisted.
            return null;
        }

        $class = $this->enclosingClassLike($scope->node);
        $fqn   = $class instanceof ClassLike ? $this->classLikeFqn($class) : null;
        if ($fqn === null) {
            // A free function or unresolved class yields no stable key, so it cannot be allowlisted.
            return null;
        }

        if ($param->flags !== 0) {
            // A promoted property is keyed by class and property only, matching the property allowlist shape.
            return sprintf('%s::%s', $fqn, $param->var->name);
        }

        if (!$scope->node instanceof ClassMethod) {
            // A plain parameter is keyed per method, so a closure or arrow function has no addressable key.
            return null;
        }

        // A plain method parameter is disambiguated by class, method, and parameter name together.
        return sprintf('%s::%s::%s', $fqn, $scope->node->name->toString(), $param->var->name);
    }

    /**
     * Resolve a class-like node to its namespace-qualified name.
     *
     * @param ClassLike $class - Class, interface, trait, or enum node being qualified.
     *
     * @return string|null - Fully qualified class-like name.
     */
    private function classLikeFqn(ClassLike $class): ?string
    {
        if ($class->name === null) {
            // Anonymous classes have no name to qualify, so no allowlist key can be derived.
            return null;
        }

        $namespace = $this->enclosingNamespace($class);
        $className = $class->name->toString();

        // Prefix the namespace when one exists so the key matches the fully qualified name a config would list.
        return $namespace instanceof Namespace_ && $namespace->name instanceof Name
            ? $namespace->name->toString() . '\\' . $className
            : $className;
    }

    /**
     * Walk parent attributes to find the enclosing class-like declaration.
     *
     * @param Node $node - Starting node whose ancestors are walked; relies on the parent attribute being set.
     *
     * @return ClassLike|null - Enclosing class, interface, trait, or enum.
     */
    private function enclosingClassLike(Node $node): ?ClassLike
    {
        $parent = $node->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof ClassLike) {
                // First class-like ancestor is the declaring type.
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        // Reached the top without a class-like ancestor, so the node lives at file scope.
        return null;
    }

    /**
     * Walk parent attributes to find the enclosing namespace.
     *
     * @param Node $node - Starting node whose ancestors are walked; relies on the parent attribute being set.
     *
     * @return Namespace_|null - Enclosing namespace node.
     */
    private function enclosingNamespace(Node $node): ?Namespace_
    {
        $parent = $node->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof Namespace_) {
                // First namespace ancestor supplies the qualifier.
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        // No namespace ancestor means the declaration sits in the global namespace.
        return null;
    }

    /**
     * Check whether a declaration type is bool or nullable bool.
     *
     * @param Node|null $type - Declared type node, or null for an untyped declaration the rule ignores.
     *
     * @return bool - True when the type resolves to bool, including ?bool and bool|null.
     */
    private function isBoolType(?Node $type): bool
    {
        if ($type instanceof NullableType) {
            // Unwrap ?bool and re-test the inner type so nullable bools still count.
            return $this->isBoolType($type->type);
        }

        if ($type instanceof Identifier) {
            // Match case-insensitively because PHP accepts Bool and BOOL as the same type.
            return $type->toLowerString() === 'bool';
        }

        if ($type instanceof Name) {
            // A bare name node can also spell bool, so compare case-insensitively too.
            return strtolower($type->toString()) === 'bool';
        }

        if ($type instanceof UnionType) {
            $nonNull = array_values(array_filter(
                $type->types,
                static fn (Node $node): bool => !($node instanceof Identifier && $node->toLowerString() === 'null'),
            ));

            // bool|null counts, but bool|int does not: exactly one non-null member and it must be bool.
            return count($nonNull) === 1 && $this->isBoolType($nonNull[0]);
        }

        // Untyped, array, object, or any other shape is not a bool flag.
        return false;
    }

    /**
     * Resolve the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope - Scope whose node names the finding; named callables and closures differ.
     *
     * @return string - Named callable symbol or synthetic closure/arrow label.
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            // Named callables reuse the shared resolver so the symbol matches other rules' output exactly.
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        // Anonymous closures and arrow functions have no name, so synthesise a kind-and-line label.
        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }
}
