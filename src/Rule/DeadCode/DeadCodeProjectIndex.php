<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\UnionType;

/**
 * Builds project-owned declaration/reference summaries for dead-code rules.
 */
final class DeadCodeProjectIndex
{
    /**
     * @var array<string, DeadCodeSymbolDeclaration>
     */
    private array $classLikeDeclarations = [];

    /**
     * @var array<string, DeadCodeSymbolDeclaration>
     */
    private array $functionDeclarations = [];

    /**
     * @var array<string, DeadCodeSymbolDeclaration>
     */
    private array $constantDeclarations = [];

    /**
     * @var array<string, list<DeadCodeSymbolReference>>
     */
    private array $classReferences = [];

    /**
     * @var array<string, list<DeadCodeSymbolReference>>
     */
    private array $functionReferences = [];

    /**
     * @var array<string, list<DeadCodeSymbolReference>>
     */
    private array $constantReferences = [];

    /**
     * Project ownership and escape-hatch decisions for the current pass.
     */
    private ?DeadCodeProjectScope $scope = null;

    /**
     * Name resolver shared by declaration and reference collection.
     */
    private readonly DeadCodeNameResolver $nameResolver;

    /**
     * Build the project index with its name resolver.
     */
    public function __construct()
    {
        $this->nameResolver = new DeadCodeNameResolver();
    }

    /**
     * Reset and configure this project index for one analysis pass.
     *
     * @param RuleContext    $ruleContext - Project root and effective config.
     * @param RuleDefinition $definition - Rule definition whose options drive ownership and escape hatches.
     *
     * @return void
     */
    public function start(RuleContext $ruleContext, RuleDefinition $definition): void
    {
        $this->classLikeDeclarations    = [];
        $this->functionDeclarations     = [];
        $this->constantDeclarations     = [];
        $this->classReferences          = [];
        $this->functionReferences       = [];
        $this->constantReferences       = [];
        $this->scope                    = DeadCodeProjectScope::fromContext($ruleContext, $definition);
    }

    /**
     * Extract declaration/reference summaries from one parsed unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to index.
     *
     * @return void
     */
    public function accumulate(AnalysisUnit $analysisUnit): void
    {
        $scope = $this->scope();
        if (!$scope->hasInternalOwnership() || $scope->isExcludedUnit($analysisUnit)) {
            // No project ownership means no project-wide dead-code claims can be made.
            return;
        }

        $this->recordDeclarations($analysisUnit);
        $this->recordReferences($analysisUnit);
    }

    /**
     * Drop accumulated state after a project pass.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->classLikeDeclarations     = [];
        $this->functionDeclarations      = [];
        $this->constantDeclarations      = [];
        $this->classReferences           = [];
        $this->functionReferences        = [];
        $this->constantReferences        = [];
        $this->scope                     = null;
    }

    /**
     * Return project-owned class-like declarations with no supported references.
     *
     * @return list<DeadCodeSymbolDeclaration> - declaration summaries in discovery order; empty when all are
     * referenced or ownership is unknown
     */
    public function unusedClassLikeDeclarations(): array
    {
        return $this->unusedDeclarations($this->classLikeDeclarations, $this->classReferences);
    }

    /**
     * Return project-owned function declarations with no supported references.
     *
     * @return list<DeadCodeSymbolDeclaration> - declaration summaries in discovery order; empty when all are
     * referenced or ownership is unknown
     */
    public function unusedFunctionDeclarations(): array
    {
        return $this->unusedDeclarations($this->functionDeclarations, $this->functionReferences);
    }

    /**
     * Return project-owned standalone constant declarations with no supported references.
     *
     * @return list<DeadCodeSymbolDeclaration> - declaration summaries in discovery order; empty when all are
     * referenced or ownership is unknown
     */
    public function unusedConstantDeclarations(): array
    {
        return $this->unusedDeclarations($this->constantDeclarations, $this->constantReferences);
    }

    /**
     * Collect project-owned declarations from a parsed unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     *
     * @return void
     */
    private function recordDeclarations(AnalysisUnit $analysisUnit): void
    {
        $scope = $this->scope();
        $skipEntrypointDeclarations = $scope->isEntrypointPath($analysisUnit->file->displayPath);
        $classLikeTypes = [Class_::class, Interface_::class, Trait_::class, Enum_::class];

        foreach (NodeIndex::nodesOfAny($analysisUnit, $classLikeTypes) as $node) {
            /** @var Class_|Interface_|Trait_|Enum_ $node - NodeIndex returned only class-like declarations. */
            $fqn = $this->nameResolver->classLikeDeclarationFqn($node);

            if ($fqn === null || $skipEntrypointDeclarations || !$scope->isInternalFqn($fqn)) {
                continue;
            }

            $this->classLikeDeclarations[$fqn] = new DeadCodeSymbolDeclaration(
                fqn:         $fqn,
                displayPath: $analysisUnit->file->displayPath,
                line:        $node->getStartLine(),
                kind:        $this->classLikeKind($node),
                attributes:  $this->attributeNames($node->attrGroups),
                isAbstract:  $node instanceof Interface_ || ($node instanceof Class_ && $node->isAbstract()),
                isTestFile:  $scope->isTestPath($analysisUnit->file->displayPath),
            );
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Function_::class) as $function) {
            $fqn = $this->nameResolver->functionDeclarationFqn($function);
            if ($skipEntrypointDeclarations || !$scope->isInternalFqn($fqn)) {
                continue;
            }

            $this->functionDeclarations[$fqn] = new DeadCodeSymbolDeclaration(
                fqn:         $fqn,
                displayPath: $analysisUnit->file->displayPath,
                line:        $function->getStartLine(),
                kind:        'function',
                attributes:  $this->attributeNames($function->attrGroups),
                isTestFile:  $scope->isTestPath($analysisUnit->file->displayPath),
            );
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Const_::class) as $constantStatement) {
            foreach ($constantStatement->consts as $constant) {
                $fqn = $this->nameResolver->constantDeclarationFqn($constant);
                if ($skipEntrypointDeclarations || !$scope->isInternalFqn($fqn)) {
                    continue;
                }

                $this->constantDeclarations[$fqn] = new DeadCodeSymbolDeclaration(
                    fqn:         $fqn,
                    displayPath: $analysisUnit->file->displayPath,
                    line:        $constant->getStartLine(),
                    kind:        'constant',
                    attributes:  $this->attributeNames($constantStatement->attrGroups),
                    isTestFile:  $scope->isTestPath($analysisUnit->file->displayPath),
                );
            }
        }
    }

    /**
     * Collect supported references from a parsed unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     *
     * @return void
     */
    private function recordReferences(AnalysisUnit $analysisUnit): void
    {
        $isTestFile = $this->scope()->isTestPath($analysisUnit->file->displayPath);

        $this->recordExpressionClassReferences($analysisUnit, $isTestFile);
        $this->recordStructuralClassReferences($analysisUnit, $isTestFile);
        $this->recordTypeReferencesInUnit($analysisUnit, $isTestFile);
        $this->recordFunctionCallReferences($analysisUnit, $isTestFile);
        $this->recordConstantFetchReferences($analysisUnit, $isTestFile);
    }

    /**
     * Record expression-level class references from one unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param bool         $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordExpressionClassReferences(AnalysisUnit $analysisUnit, bool $isTestFile): void
    {
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\New_::class) as $node) {
            $this->recordClassReferenceNode($node->class, $node, $isTestFile);
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $node) {
            $this->recordClassReferenceNode($node->class, $node, $isTestFile);
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticPropertyFetch::class) as $node) {
            $this->recordClassReferenceNode($node->class, $node, $isTestFile);
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\ClassConstFetch::class) as $node) {
            $this->recordClassReferenceNode($node->class, $node, $isTestFile);
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Instanceof_::class) as $node) {
            $this->recordClassReferenceNode($node->class, $node, $isTestFile);
        }
    }

    /**
     * Record declaration-structure class references from one unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param bool         $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordStructuralClassReferences(AnalysisUnit $analysisUnit, bool $isTestFile): void
    {
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Catch_::class) as $node) {
            foreach ($node->types as $type) {
                $this->recordClassNameReference($type, $node, $isTestFile);
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Attribute::class) as $attribute) {
            $this->recordClassNameReference($attribute->name, $attribute, $isTestFile);
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Class_::class) as $class) {
            $this->recordClassReferenceNode($class->extends, $class, $isTestFile);
            foreach ($class->implements as $interfaceName) {
                $this->recordClassNameReference($interfaceName, $class, $isTestFile);
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Interface_::class) as $interface) {
            foreach ($interface->extends as $extendedName) {
                $this->recordClassNameReference($extendedName, $interface, $isTestFile);
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Enum_::class) as $enum) {
            foreach ($enum->implements as $interfaceName) {
                $this->recordClassNameReference($interfaceName, $enum, $isTestFile);
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\TraitUse::class) as $traitUse) {
            foreach ($traitUse->traits as $traitName) {
                $this->recordClassNameReference($traitName, $traitUse, $isTestFile);
            }
        }
    }

    /**
     * Record type declaration references from one unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param bool         $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordTypeReferencesInUnit(AnalysisUnit $analysisUnit, bool $isTestFile): void
    {
        $typedNodeClasses = [Param::class, ClassMethod::class, Function_::class, Property::class];

        foreach (NodeIndex::nodesOfAny($analysisUnit, $typedNodeClasses) as $node) {
            $this->recordTypeReferences($node, $isTestFile);
        }
    }

    /**
     * Record direct and first-class function-call references from one unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param bool         $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordFunctionCallReferences(AnalysisUnit $analysisUnit, bool $isTestFile): void
    {
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $functionCall) {
            if ($functionCall->name instanceof Name) {
                $fqns = $this->nameResolver->resolveFunctionOrConstantName($functionCall->name, $functionCall);
                $this->recordFunctionReferences($fqns, $functionCall, $isTestFile);
            }
        }
    }

    /**
     * Record direct standalone constant references from one unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param bool         $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordConstantFetchReferences(AnalysisUnit $analysisUnit, bool $isTestFile): void
    {
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\ConstFetch::class) as $constantFetch) {
            if (in_array(strtolower($constantFetch->name->toString()), ['true', 'false', 'null'], true)) {
                continue;
            }

            $this->recordConstantReferences(
                $this->nameResolver->resolveFunctionOrConstantName($constantFetch->name, $constantFetch),
                $isTestFile,
            );
        }
    }

    /**
     * Record class references found in a type node.
     *
     * @param Node     $node - Param, method/function, or property node.
     * @param bool     $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordTypeReferences(Node $node, bool $isTestFile): void
    {
        if ($node instanceof Param) {
            $this->recordTypeReference($node->type, $node, $isTestFile);
            return;
        }

        if ($node instanceof ClassMethod || $node instanceof Function_) {
            $this->recordTypeReference($node->returnType, $node, $isTestFile);
            foreach ($node->params as $param) {
                $this->recordTypeReference($param->type, $param, $isTestFile);
            }
            return;
        }

        if ($node instanceof Property) {
            $this->recordTypeReference($node->type, $node, $isTestFile);
        }
    }

    /**
     * Record references from one type expression.
     *
     * @param Identifier|Name|ComplexType|null $type - Type node to inspect.
     * @param Node                             $originNode - Node carrying the type.
     * @param bool                             $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordTypeReference(
        Identifier|Name|ComplexType|null $type,
        Node $originNode,
        bool $isTestFile,
    ): void {
        if ($type instanceof Name) {
            $this->recordClassNameReference($type, $originNode, $isTestFile);
            return;
        }

        if ($type instanceof NullableType) {
            $this->recordTypeReference($type->type, $originNode, $isTestFile);
            return;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $innerType) {
                $this->recordTypeReference($innerType, $originNode, $isTestFile);
            }
        }
    }

    /**
     * Record a class reference from a node that may be a name or dynamic expression.
     *
     * @param Node|null $classNode - Candidate class node.
     * @param Node      $originNode - Node where the reference appears.
     * @param bool      $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordClassReferenceNode(?Node $classNode, Node $originNode, bool $isTestFile): void
    {
        if ($classNode instanceof Name) {
            $this->recordClassNameReference($classNode, $originNode, $isTestFile);
        }
    }

    /**
     * Record a class reference from a name node.
     *
     * @param Name $name - Class name node.
     * @param Node $originNode - Node where the reference appears.
     * @param bool $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordClassNameReference(Name $name, Node $originNode, bool $isTestFile): void
    {
        $fqn = $this->nameResolver->resolveClassName($name, $originNode);
        if ($fqn === null || !$this->scope()->isInternalFqn($fqn)) {
            return;
        }

        $this->classReferences[$fqn][] = new DeadCodeSymbolReference(
            fqn:          $fqn,
            originSymbol: $this->nameResolver->enclosingClassFqn($originNode),
            isTestFile:   $isTestFile,
        );
    }

    /**
     * Record function references.
     *
     * @param list<string> $fqns - Candidate resolved names.
     * @param Node         $originNode - Node where the reference appears.
     * @param bool         $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordFunctionReferences(array $fqns, Node $originNode, bool $isTestFile): void
    {
        foreach ($fqns as $fqn) {
            if (!$this->scope()->isInternalFqn($fqn)) {
                continue;
            }

            $this->functionReferences[$fqn][] = new DeadCodeSymbolReference(
                fqn:          $fqn,
                originSymbol: $this->nameResolver->enclosingFunctionFqn($originNode),
                isTestFile:   $isTestFile,
            );
        }
    }

    /**
     * Record constant references.
     *
     * @param list<string> $fqns - Candidate resolved names.
     * @param bool         $isTestFile - Whether the containing unit is a test file.
     *
     * @return void
     */
    private function recordConstantReferences(array $fqns, bool $isTestFile): void
    {
        foreach ($fqns as $fqn) {
            if (!$this->scope()->isInternalFqn($fqn)) {
                continue;
            }

            $this->constantReferences[$fqn][] = new DeadCodeSymbolReference(
                fqn:          $fqn,
                originSymbol: null,
                isTestFile:   $isTestFile,
            );
        }
    }

    /**
     * Filter declarations down to symbols with no live references.
     *
     * @param array<string, DeadCodeSymbolDeclaration>          $declarations - Declarations keyed by FQN.
     * @param array<string, list<DeadCodeSymbolReference>>      $references - References keyed by FQN.
     *
     * @return list<DeadCodeSymbolDeclaration> - declarations that survived every entrypoint/framework/reference filter
     */
    private function unusedDeclarations(array $declarations, array $references): array
    {
        $unused = [];
        $scope  = $this->scope();

        foreach ($declarations as $fqn => $declaration) {
            // PHPUnit/Pest discover test classes externally; their declarations are runner entrypoints, not dead code.
            if ($declaration->isTestFile
                || $scope->isEntrypointSymbol($fqn)
                || $scope->isEntrypointPath($declaration->displayPath)
                || $scope->hasFrameworkAttribute($declaration)
                || $this->hasLiveReference($declaration, $references[$fqn] ?? [])
            ) {
                continue;
            }

            $unused[] = $declaration;
        }

        // Discovery order is stable because declarations are appended while units are traversed.
        return $unused;
    }

    /**
     * Decide whether a declaration has a supported reference that keeps it live.
     *
     * @param DeadCodeSymbolDeclaration  $declaration - Declaration being checked.
     * @param list<DeadCodeSymbolReference> $references - Candidate references to the declaration.
     *
     * @return bool - true when at least one non-self reference is live for this configuration
     */
    private function hasLiveReference(DeadCodeSymbolDeclaration $declaration, array $references): bool
    {
        foreach ($references as $reference) {
            if (!$this->scope()->shouldTreatTestsAsReferences() && $reference->isTestFile) {
                continue;
            }

            if ($reference->originSymbol === $declaration->fqn) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Resolve attribute names.
     *
     * @param array<array-key, Node\AttributeGroup> $attributeGroups - Attribute groups on a declaration.
     *
     * @return list<string> - attribute names without leading slash
     */
    private function attributeNames(array $attributeGroups): array
    {
        $attributes = [];
        foreach ($attributeGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                /** @var Attribute $attribute - AttributeGroup narrows attrs to concrete Attribute nodes. */
                $resolvedName = $this->nameResolver->resolveClassName($attribute->name, $attribute);
                $attributes[] = $resolvedName ?? ltrim($attribute->name->toString(), '\\');
            }
        }

        return $attributes;
    }

    /**
     * Return the configured project scope for the current pass.
     *
     * @return DeadCodeProjectScope - scope initialized by start()
     */
    private function scope(): DeadCodeProjectScope
    {
        if (!$this->scope instanceof DeadCodeProjectScope) {
            throw new \LogicException('Dead-code project scope has not been initialized.');
        }

        return $this->scope;
    }

    /**
     * Classify a class-like node for metadata and messages.
     *
     * @param Class_|Interface_|Trait_|Enum_ $node - Class-like declaration.
     *
     * @return string - one of class, interface, trait, or enum
     */
    private function classLikeKind(Class_|Interface_|Trait_|Enum_ $node): string
    {
        return match (true) {
            $node instanceof Interface_ => 'interface',
            $node instanceof Trait_     => 'trait',
            $node instanceof Enum_      => 'enum',
            default                     => 'class',
        };
    }

}
