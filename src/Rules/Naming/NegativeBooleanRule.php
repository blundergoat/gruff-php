<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

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
 * Flags a typed bool property or parameter named as a negative flag - `$noCache`, `$disableLogging` - because
 * such names force the reader through a double negative at every call site (`if (!$noCache)`).
 *
 * A name can opt out by mirroring a real CLI flag through the cliMirrorAllowlist option. Advisory, medium
 * confidence. Overlap deferral order is centralised in RuleRegistry:
 * class-file-mismatch > confusing-name > negative-boolean > boolean-prefix >
 * identifier-quality > hungarian-notation > suffix-hungarian > short-variable >
 * abbreviation-allowlist.
 */
final readonly class NegativeBooleanRule implements RuleInterface
{
    /** Stable identifier for the negative-boolean rule. */
    public const ID = 'naming.negative-boolean';

    /**
     * Negative prefixes that make boolean flags harder to read.
     *
     * Public because BooleanPrefixRule defers matching names to this rule; both rules
     * must agree on the prefix set and the boundary rules or names fall between them.
     */
    public const NEGATIVE_PREFIXES = ['no', 'not', 'non', 'disable', 'skip', 'dont', 'cant', 'wont'];

    /**
     * Describes the negative-boolean rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
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
            falsePositiveShapes: [
                [
                    'shape'      => 'A Boolean that deliberately mirrors an external negative contract, such as $noCache for a --no-cache option or a wire-format noStore field.',
                    'mitigation' => 'Renaming it positive would invert the mirrored contract, so add the finding\'s fully qualified allowlistKey to options.cliMirrorAllowlist.',
                ],
            ],
        );
    }

    /**
     * Reports typed bool properties and parameters named as negative flags.
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

        // Check every typed boolean property declared in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            // Skip a property that is not typed bool.
            if (!$this->isBoolType($property->type)) {
                continue;
            }

            // One declaration can name several properties, so check each in turn.
            foreach ($property->props as $prop) {
                $finding = $this->propertyFinding(
                    definition:       $definition,
                    analysisUnit:     $analysisUnit,
                    propertyProperty: $prop,
                    allowlist:        $allowlist,
                );
                // Keep the property only when it actually named a negative flag.
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        // Check every parameter across all function-like scopes.
        foreach ((new FunctionLikeScopeWalker())->scopes($analysisUnit->statements) as $scope) {
            // Weigh each declared parameter.
            foreach ($scope->node->params as $param) {
                $finding = $this->parameterFinding(
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    scope:        $scope,
                    param:        $param,
                    allowlist:    $allowlist,
                );
                // Keep the parameter only when it actually named a negative flag.
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Builds a finding for a negatively-named boolean property, unless the project allowlisted it.
     *
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
        $prefix       = self::negativeFlagPrefix($name);
        $allowlistKey = $this->propertyAllowlistKey($propertyProperty);

        // No negative prefix, or an explicit CLI-mirror opt-out, means nothing to report.
        if ($prefix === null || ($allowlistKey !== null && in_array($allowlistKey, $allowlist, true))) {
            return null;
        }

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
     * Builds a finding for a negatively-named boolean parameter, unless the project allowlisted it.
     *
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
        // Only a plainly named bool parameter can be judged.
        if (!$this->isBoolType($param->type) || !$param->var instanceof Variable || !is_string($param->var->name)) {
            return null;
        }

        $name         = $param->var->name;
        $prefix       = self::negativeFlagPrefix($name);
        $allowlistKey = $this->parameterAllowlistKey($scope, $param);

        // No negative prefix, or an explicit CLI-mirror opt-out, means nothing to report.
        if ($prefix === null || ($allowlistKey !== null && in_array($allowlistKey, $allowlist, true))) {
            return null;
        }

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
     * Builds a negative-boolean finding.
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
     * Detects the configured negative prefix at a camelCase or snake_case word boundary.
     *
     * Shared with BooleanPrefixRule's deferral check so `no_cache` and `noCache` are
     * owned by exactly one rule; `north`/`normalised`-style words never match because
     * the prefix must end at an uppercase letter or underscore boundary.
     *
     * @param string $name - Identifier to test, without its leading sigil.
     *
     * @return string|null - Matched prefix, or null when the name is acceptable.
     */
    public static function negativeFlagPrefix(string $name): ?string
    {
        // Try each known negative word against the front of the identifier.
        foreach (self::NEGATIVE_PREFIXES as $prefix) {
            // The prefix must start the name and leave at least one character after it.
            if (!str_starts_with($name, $prefix) || strlen($name) <= strlen($prefix)) {
                continue;
            }

            $nextChar = $name[strlen($prefix)];
            // Only a word boundary counts, so plain words like "north" never read as negatives.
            if (($nextChar >= 'A' && $nextChar <= 'Z') || $nextChar === '_') {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * Builds the allowlist key for a property when its declaring class is known.
     *
     * @param PropertyProperty $propertyProperty - Declared property whose fully qualified key is being built.
     *
     * @return string|null - Fully qualified property key.
     */
    private function propertyAllowlistKey(PropertyProperty $propertyProperty): ?string
    {
        $class = $this->enclosingClassLike($propertyProperty);
        $fqn   = $class instanceof ClassLike ? $this->classLikeFqn($class) : null;

        return $fqn === null ? null : sprintf('%s::%s', $fqn, $propertyProperty->name->toString());
    }

    /**
     * Builds the allowlist key for a parameter or promoted property.
     *
     * @param FunctionLikeScope $scope - Enclosing scope whose node resolves the declaring class and method name.
     * @param Param             $param - Parameter whose name and promotion flags shape the key.
     *
     * @return string|null - Fully qualified parameter key.
     */
    private function parameterAllowlistKey(FunctionLikeScope $scope, Param $param): ?string
    {
        // A variadic or dynamically named parameter has no name to key on.
        if (!$param->var instanceof Variable || !is_string($param->var->name)) {
            return null;
        }

        $class = $this->enclosingClassLike($scope->node);
        $fqn   = $class instanceof ClassLike ? $this->classLikeFqn($class) : null;
        // Without a resolved class name there is no fully qualified key.
        if ($fqn === null) {
            return null;
        }

        // A promoted parameter is a class property, so it keys like one.
        if ($param->flags !== 0) {
            return sprintf('%s::%s', $fqn, $param->var->name);
        }

        // A plain function parameter has no method segment to key on.
        if (!$scope->node instanceof ClassMethod) {
            return null;
        }

        return sprintf('%s::%s::%s', $fqn, $scope->node->name->toString(), $param->var->name);
    }

    /**
     * Resolves a class-like node to its namespace-qualified name.
     *
     * @param ClassLike $class - Class, interface, trait, or enum node being qualified.
     *
     * @return string|null - Fully qualified class-like name.
     */
    private function classLikeFqn(ClassLike $class): ?string
    {
        // An anonymous class cannot be named in an allowlist key.
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
     * Walks the parent chain to the enclosing class-like declaration.
     *
     * @param Node $node - Starting node whose ancestors are walked; relies on the parent attribute being set.
     *
     * @return ClassLike|null - Enclosing class, interface, trait, or enum.
     */
    private function enclosingClassLike(Node $node): ?ClassLike
    {
        $parent = $node->getAttribute('parent');

        // Walk outward until a class-like ancestor appears.
        while ($parent instanceof Node) {
            // The nearest class-like ancestor is the declaring type.
            if ($parent instanceof ClassLike) {
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * Walks the parent chain to the enclosing namespace.
     *
     * @param Node $node - Starting node whose ancestors are walked; relies on the parent attribute being set.
     *
     * @return Namespace_|null - Enclosing namespace node.
     */
    private function enclosingNamespace(Node $node): ?Namespace_
    {
        $parent = $node->getAttribute('parent');

        // Walk outward until a namespace ancestor appears.
        while ($parent instanceof Node) {
            // The nearest namespace ancestor qualifies the name.
            if ($parent instanceof Namespace_) {
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * Reports whether a declaration type is bool or nullable bool.
     *
     * @param Node|null $type - Declared type node, or null for an untyped declaration the rule ignores.
     *
     * @return bool - True when the type resolves to bool, including ?bool and bool|null.
     */
    private function isBoolType(?Node $type): bool
    {
        // Unwrap `?T` so `?bool` still counts as bool.
        if ($type instanceof NullableType) {
            return $this->isBoolType($type->type);
        }

        // A bare `bool` keyword is the scalar we want.
        if ($type instanceof Identifier) {
            return $type->toLowerString() === 'bool';
        }

        // A name token spelling `bool` still resolves to the scalar.
        if ($type instanceof Name) {
            return strtolower($type->toString()) === 'bool';
        }

        // A union counts only when its sole non-null member is bool, i.e. exactly `bool|null`.
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
     * Resolves the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope - Scope whose node names the finding; named callables and closures differ.
     *
     * @return string - Named callable symbol or synthetic closure/arrow label.
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        // Named callables resolve to their declared symbol.
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        // Closures and arrow functions have no name, so fall back to a kind@line label.
        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }
}
