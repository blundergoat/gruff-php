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
use GruffPhp\Rule\Modernisation\ModernisationNodeHelper;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;

/**
 * Detects placeholder, generic, and numbered identifiers that obscure intent.
 */
final readonly class IdentifierQualityRule implements RuleInterface
{
    /**
     * Stable identifier for the identifier quality rule.
     */
    public const ID = 'naming.identifier-quality';

    /**
     * Placeholder names that rarely communicate domain intent.
     */
    private const DEFAULT_PLACEHOLDER_NAMES = ['foo', 'bar', 'baz', 'tmp', 'temp', 'obj', 'arr'];

    /**
     * Generic tokens that need enough scope usage to be acceptable.
     */
    private const DEFAULT_GENERIC_TOKENS = ['data', 'entry', 'info', 'input', 'item', 'row', 'thing', 'stuff', 'helper', 'util', 'value'];

    /**
     * Short conventional names ignored by the identifier quality rule.
     */
    private const DEFAULT_IGNORED_NAMES = [
        '_',
        'e',
        'exception',
        'throwable',
        'i',
        'j',
        'k',
        'idx',
        'index',
        'input',
        'key',
    ];

    /**
     * PHP magic methods exempt from generic method-name checks.
     */
    private const MAGIC_METHODS = [
        '__construct',
        '__destruct',
        '__clone',
        '__toString',
        '__debugInfo',
        '__get',
        '__set',
        '__isset',
        '__unset',
        '__call',
        '__callStatic',
        '__invoke',
        '__sleep',
        '__wakeup',
        '__serialize',
        '__unserialize',
        '__set_state',
    ];

    /**
     * Framework and lifecycle hooks exempt from generic method-name checks.
     */
    private const LIFECYCLE_METHODS = [
        'setUp',
        'tearDown',
        'setUpBeforeClass',
        'tearDownAfterClass',
        'configure',
        'execute',
        'initialize',
        'interact',
    ];

    /**
     * Describe the identifier quality rule.
     *
     * @return RuleDefinition Rule metadata, defaults, and options.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Identifier quality',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  [
                'placeholderNames' => self::DEFAULT_PLACEHOLDER_NAMES,
                'genericTokens' => self::DEFAULT_GENERIC_TOKENS,
                'ignoredNames' => self::DEFAULT_IGNORED_NAMES,
                'minScopeReferences' => 1,
                'loopBodyThreshold' => 4,
            ],
            description: 'Catches placeholder, generic, and numbered identifiers that obscure intent.',
        );
    }

    /**
     * Find placeholder, generic, and numbered identifiers across declarations and locals.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for low-quality identifiers.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition     = $this->definition();
        $findingContext = $this->findingContext($analysisUnit, $ruleContext, $definition);

        return [
            ...$this->classLikeFindings($findingContext),
            ...$this->functionLikeFindings(
                findingContext:     $findingContext,
                minScopeReferences: $this->minScopeReferences($ruleContext, $definition),
                loopBodyThreshold:  $this->loopBodyThreshold($ruleContext, $definition),
            ),
            ...$this->propertyFindings($findingContext),
        ];
    }

    /**
     * Build shared finding inputs from rule settings.
     *
     * @return IdentifierFindingContext Shared context for identifier finding checks.
     */
    private function findingContext(
        AnalysisUnit $analysisUnit,
        RuleContext $ruleContext,
        RuleDefinition $definition,
    ): IdentifierFindingContext {
        $settings = $ruleContext->settingsFor($definition);

        return new IdentifierFindingContext(
            definition:            $definition,
            analysisUnit:          $analysisUnit,
            tokenizer:             new IdentifierTokenizer(),
            placeholderNames:      $this->lowercaseList($settings->stringListOption('placeholderNames')),
            genericTokens:         $this->lowercaseList($settings->stringListOption('genericTokens')),
            ignoredNames:          $this->lowercaseList($settings->stringListOption('ignoredNames')),
            acceptedAbbreviations: $this->lowercaseList($ruleContext->config->acceptedAbbreviations()),
        );
    }

    /**
     * Resolve the minimum local-variable reference count needed before reporting.
     *
     * @return int Minimum number of local variable reads.
     */
    private function minScopeReferences(RuleContext $ruleContext, RuleDefinition $definition): int
    {
        $minScopeOption = $ruleContext->settingsFor($definition)->option('minScopeReferences');

        return is_int($minScopeOption) ? max(1, $minScopeOption) : 1;
    }

    /**
     * Resolve the foreach body-size threshold before generic loop variables report.
     *
     * @return int Minimum statement count for generic foreach loop-variable findings.
     */
    private function loopBodyThreshold(RuleContext $ruleContext, RuleDefinition $definition): int
    {
        $thresholdOption = $ruleContext->settingsFor($definition)->option('loopBodyThreshold');

        return is_int($thresholdOption) ? max(1, $thresholdOption) : 4;
    }

    /**
     * Find low-quality class, interface, trait, and enum names.
     *
     * @return list<Finding> Findings for class-like identifiers.
     */
    private function classLikeFindings(IdentifierFindingContext $findingContext): array
    {
        $findings = [];

        $classLikes = NodeIndex::nodesOfAny(
            $findingContext->analysisUnit,
            [Class_::class, Interface_::class, Trait_::class, Enum_::class],
        );

        foreach ($classLikes as $node) {
            /** @var Class_|Interface_|Trait_|Enum_ $node NodeIndex query is constrained to class-like classes. */
            $name = $node->name?->toString();
            if ($name === null) {
                continue;
            }

            $finding = $this->finding(
                identifierFindingContext: $findingContext,
                node:                     $node,
                kind:                     $this->classLikeKind($node),
                name:                     $name,
                symbol:                   $name,
            );

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Find low-quality function-like names, parameters, and local variables.
     *
     * @return list<Finding> Findings for function-like identifier scopes.
     */
    private function functionLikeFindings(
        IdentifierFindingContext $findingContext,
        int $minScopeReferences,
        int $loopBodyThreshold,
    ): array {
        $findings = [];

        foreach ((new FunctionLikeScopeWalker())->scopes($findingContext->analysisUnit->statements) as $scope) {
            $function         = $scope->node;
            $functionFindings = $function instanceof ClassMethod || $function instanceof Function_
                ? $this->functionNameFindings($findingContext, $function)
                : [];

            array_push(
                $findings,
                ...$functionFindings,
                ...$this->parameterFindings($findingContext, $scope),
                ...$this->localVariableFindings($findingContext, $scope, $minScopeReferences, $loopBodyThreshold),
            );
        }

        return $findings;
    }

    /**
     * Find a low-quality method or function name.
     *
     * @return list<Finding> Empty when the function-like name is exempt or acceptable.
     */
    private function functionNameFindings(IdentifierFindingContext $findingContext, ClassMethod|Function_ $function): array
    {
        if ($this->shouldSkipFunctionLike($function)) {
            return [];
        }

        $finding = $this->finding(
            identifierFindingContext: $findingContext,
            node:                     $function,
            kind:                     $function instanceof ClassMethod ? 'method' : 'function',
            name:                     $function->name->toString(),
            symbol:                   CyclomaticComplexityRule::resolveSymbol($function),
        );

        return $finding instanceof Finding ? [$finding] : [];
    }

    /**
     * Find low-quality parameter and promoted-property names in one function-like scope.
     *
     * @return list<Finding> Findings for parameters and promoted properties.
     */
    private function parameterFindings(IdentifierFindingContext $findingContext, FunctionLikeScope $scope): array
    {
        $findings              = [];
        $symbol                = $this->symbol($scope);
        $skipGenericComplaints = $this->isGenericByPurposeHelper($scope);

        foreach ($scope->node->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $finding = $this->finding(
                identifierFindingContext: $findingContext,
                node:                     $param,
                kind:                     $param->flags === 0 ? 'parameter' : 'property',
                name:                     $param->var->name,
                symbol:                   $symbol,
            );

            if (!$finding instanceof Finding) {
                continue;
            }

            if ($skipGenericComplaints && ($finding->metadata['variant'] ?? null) === 'generic') {
                continue;
            }

            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * Detect a single-parameter, wide-typed, non-`void`-returning helper whose sole parameter
     * legitimately needs a generic name. The shape covers helpers like
     * `private static function stringValue(mixed $value): string` whose intent is "coerce anything
     * into the documented return type"; a generic parameter name (`$value`) is the right name there.
     */
    private function isGenericByPurposeHelper(FunctionLikeScope $scope): bool
    {
        $node = $scope->node;
        if (!$node instanceof ClassMethod && !$node instanceof Function_) {
            return false;
        }

        if (count($node->params) !== 1) {
            return false;
        }

        if (ModernisationNodeHelper::typeName($node->returnType) === 'void') {
            return false;
        }

        $type = $node->params[0]->type;

        if ($type instanceof Node\UnionType && count($type->types) >= 3) {
            return true;
        }

        $typeName = ModernisationNodeHelper::typeName($type);

        return $typeName === 'mixed' || $typeName === 'scalar';
    }

    /**
     * Find low-quality local variable names in one function-like scope.
     *
     * @return list<Finding> Findings for local variables.
     */
    private function localVariableFindings(
        IdentifierFindingContext $findingContext,
        FunctionLikeScope $scope,
        int $minScopeReferences,
        int $loopBodyThreshold,
    ): array {
        $findings  = [];
        $symbol    = $this->symbol($scope);
        $loopVars  = $this->loopVariables($scope);
        $catchVars = $this->catchVariables($scope);

        foreach ($this->localVariableNames($scope, $minScopeReferences, $loopVars + $catchVars) as $name => $variable) {
            $finding = $this->finding(
                identifierFindingContext: $findingContext,
                node:                     $variable,
                kind:                     'variable',
                name:                     $name,
                symbol:                   $symbol,
            );

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        $loopIgnoredNames = array_values(array_diff($findingContext->ignoredNames, $findingContext->genericTokens));
        foreach ($this->reportableLoopVariableNames($scope, $findingContext->genericTokens, $loopBodyThreshold) as $name => $variable) {
            $finding = $this->finding(
                identifierFindingContext: $findingContext,
                node:                     $variable,
                kind:                     'variable',
                name:                     $name,
                symbol:                   $symbol,
                ignoredNamesOverride:     $loopIgnoredNames,
            );

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Find low-quality declared property names.
     *
     * @return list<Finding> Findings for property identifiers.
     */
    private function propertyFindings(IdentifierFindingContext $findingContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($findingContext->analysisUnit, Property::class) as $property) {
            foreach ($property->props as $prop) {
                $name    = $prop->name->toString();
                $finding = $this->finding(
                    identifierFindingContext: $findingContext,
                    node:                     $prop,
                    kind:                     'property',
                    name:                     $name,
                    symbol:                   '$' . $name,
                );

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<string>|null $ignoredNamesOverride Optional ignored-name list for loop-variable checks.
     * @return Finding|null Identifier finding, or null when the name is acceptable/ignored.
     */
    private function finding(
        IdentifierFindingContext $identifierFindingContext,
        Node $node,
        string $kind,
        string $name,
        ?string $symbol,
        ?array $ignoredNamesOverride = null,
    ): ?Finding {
        $ignoredNames = $ignoredNamesOverride ?? $identifierFindingContext->ignoredNames;
        if ($this->isIgnored($name, $ignoredNames, $identifierFindingContext->acceptedAbbreviations)) {
            return null;
        }

        $tokens = $identifierFindingContext->tokenizer->tokenize($name);
        if ($tokens === []) {
            return null;
        }

        $variant      = null;
        $matchedToken = null;
        $lowerName    = strtolower(ltrim($name, '$'));

        if (in_array($lowerName, $identifierFindingContext->placeholderNames, true)) {
            $variant      = 'placeholder';
            $matchedToken = $lowerName;
        } elseif ($this->allTokensMatch($tokens, $identifierFindingContext->genericTokens)) {
            $variant      = 'generic';
            $matchedToken = implode(' ', $tokens);
        } elseif ($this->isNumberedIdentifier(
            name:                  $name,
            tokens:                $tokens,
            genericTokens:         $identifierFindingContext->genericTokens,
            placeholderNames:      $identifierFindingContext->placeholderNames,
            acceptedAbbreviations: $identifierFindingContext->acceptedAbbreviations,
        )) {
            $variant      = 'numbered';
            $matchedToken = $tokens[array_key_last($tokens)];
        }

        if ($variant === null) {
            return null;
        }

        return new Finding(
            ruleId:      $identifierFindingContext->definition->id,
            message:     sprintf('%s name "%s" is %s and does not communicate clear intent.', ucfirst($kind), $name, $variant),
            filePath:    $identifierFindingContext->analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $identifierFindingContext->definition->defaultSeverity,
            pillar:      $identifierFindingContext->definition->pillar,
            tier:        $identifierFindingContext->definition->tier,
            confidence:  $identifierFindingContext->definition->confidence,
            symbol:      $symbol,
            remediation: 'Rename the identifier to describe its domain role or action.',
            metadata:    [
                'identifierKind' => $kind,
                'identifierName' => $name,
                'variant' => $variant,
                'tokens' => $tokens,
                'matchedToken' => $matchedToken,
            ],
        );
    }

    /**
     * @param list<string> $ignoredNames
     * @param list<string> $acceptedAbbreviations
     *
     * @return bool True when the name should be skipped by this rule.
     */
    private function isIgnored(string $name, array $ignoredNames, array $acceptedAbbreviations): bool
    {
        $lowerName = strtolower($name);

        if (str_starts_with($name, '_')) {
            return true;
        }

        if (in_array($lowerName, $ignoredNames, true)) {
            return true;
        }

        return in_array($lowerName, $acceptedAbbreviations, true);
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $genericTokens
     *
     * @return bool True when every token is a configured generic token.
     */
    private function allTokensMatch(array $tokens, array $genericTokens): bool
    {
        foreach ($tokens as $token) {
            if (!in_array($token, $genericTokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $genericTokens
     * @param list<string> $placeholderNames
     * @param list<string> $acceptedAbbreviations
     *
     * @return bool True when the identifier is a weak numbered variant.
     */
    private function isNumberedIdentifier(
        string $name,
        array $tokens,
        array $genericTokens,
        array $placeholderNames,
        array $acceptedAbbreviations,
    ): bool {
        if (count($tokens) < 2 || !ctype_digit($tokens[array_key_last($tokens)])) {
            return false;
        }

        $prefixTokens = array_slice($tokens, 0, -1);
        $prefix       = implode('', $prefixTokens);

        if (in_array($prefix, $acceptedAbbreviations, true)) {
            return false;
        }

        // Permit acronym-style identifiers that are only disambiguated by a trailing number.
        if (preg_match('/[A-Z]{2,}\d+$/', $name) === 1) {
            return false;
        }

        return in_array($prefix, $placeholderNames, true) || $this->allTokensMatch($prefixTokens, $genericTokens);
    }

    /** @return bool True when framework lifecycle or data-provider methods should be skipped. */
    private function shouldSkipFunctionLike(ClassMethod|Function_ $node): bool
    {
        $name = $node->name->toString();

        if ($node instanceof ClassMethod && in_array($name, self::MAGIC_METHODS, true)) {
            return true;
        }

        if ($node instanceof ClassMethod && in_array($name, self::LIFECYCLE_METHODS, true)) {
            return true;
        }

        return str_starts_with($name, 'provide') || str_ends_with($name, 'Provider');
    }

    /**
     * @param array<string, true> $excludedNames Names already exempted by surrounding rule logic.
     * @return array<string, Variable> Local variables that should be checked for naming quality.
     */
    private function localVariableNames(FunctionLikeScope $scope, int $minScopeReferences, array $excludedNames): array
    {
        $counts    = $this->localVariableReferenceCounts($scope);
        $variables = [];

        foreach ($scope->localVariables as $name => $variable) {
            if (isset($excludedNames[$name])) {
                continue;
            }

            if (($counts[$name] ?? 0) >= $minScopeReferences) {
                $variables[$name] = $variable;
            }
        }

        return $variables;
    }

    /** @return array<string, true> Variables introduced by loop constructs. */
    private function loopVariables(FunctionLikeScope $scope): array
    {
        $variables = [];

        foreach ($this->nodesInScope($scope, static fn (Node $node): bool => $node instanceof For_ || $node instanceof Foreach_) as $loop) {
            if ($loop instanceof For_) {
                $this->collectVariablesByName($loop->init, $variables);
            }

            if ($loop instanceof Foreach_) {
                foreach ([$loop->keyVar, $loop->valueVar] as $variable) {
                    if ($variable instanceof Variable && is_string($variable->name)) {
                        $variables[$variable->name] = true;
                    }
                }
            }
        }

        return $variables;
    }

    /** @return array<string, true> Variables introduced by catch clauses. */
    private function catchVariables(FunctionLikeScope $scope): array
    {
        $variables = [];

        foreach ($this->nodesInScope($scope, static fn (Node $node): bool => $node instanceof Catch_) as $catch) {
            if (!$catch instanceof Catch_) {
                continue;
            }

            if ($catch->var instanceof Variable && is_string($catch->var->name)) {
                $variables[$catch->var->name] = true;
            }
        }

        return $variables;
    }

    /**
     * @param list<string> $genericTokens Lowercase loop variable names treated as generic.
     * @return array<string, Variable> Loop variables that should be reported.
     */
    private function reportableLoopVariableNames(
        FunctionLikeScope $scope,
        array $genericTokens,
        int $loopBodyThreshold,
    ): array {
        $variables = [];

        foreach ($this->nodesInScope($scope, static fn (Node $node): bool => $node instanceof Foreach_) as $foreach) {
            if (!$foreach instanceof Foreach_) {
                continue;
            }

            if (count($foreach->stmts) < $loopBodyThreshold || $this->isCanonicalMapLoop($foreach)) {
                continue;
            }

            foreach ([$foreach->keyVar, $foreach->valueVar] as $variable) {
                if (!$variable instanceof Variable || !is_string($variable->name)) {
                    continue;
                }

                $name = strtolower($variable->name);
                if (in_array($name, $genericTokens, true)) {
                    $variables[$variable->name] ??= $variable;
                }
            }
        }

        return $variables;
    }

    /** @return bool True for the conventional key/value map iteration idiom. */
    private function isCanonicalMapLoop(Foreach_ $foreach): bool
    {
        return $foreach->keyVar instanceof Variable
            && $foreach->valueVar instanceof Variable
            && $foreach->keyVar->name === 'key'
            && $foreach->valueVar->name === 'value';
    }

    /** @return array<string, int> Local variable read counts keyed by variable name. */
    private function localVariableReferenceCounts(FunctionLikeScope $scope): array
    {
        $counts = [];

        foreach ($this->nodesInScope($scope, static fn (Node $node): bool => $node instanceof Variable) as $variable) {
            if ($variable instanceof Variable && is_string($variable->name)) {
                $counts[$variable->name] = ($counts[$variable->name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array<Node>        $nodes     AST nodes to scan for variable references.
     * @param array<string,true> $variables Output set keyed by variable name.
     * @return void
     */
    private function collectVariablesByName(array $nodes, array &$variables): void
    {
        foreach ($nodes as $node) {
            foreach ($this->nodesMatching([$node], static fn (Node $candidate): bool => $candidate instanceof Variable) as $variable) {
                if ($variable instanceof Variable && is_string($variable->name)) {
                    $variables[$variable->name] = true;
                }
            }
        }
    }

    /**
     * @param callable(Node): bool $predicate Predicate that selects matching descendants.
     * @return list<Node> Descendant nodes in the current function-like scope.
     */
    private function nodesInScope(FunctionLikeScope $scope, callable $predicate): array
    {
        $matches = [];

        foreach ($scope->bodyDescendants as $node) {
            if ($predicate($node)) {
                $matches[] = $node;
            }
        }

        return $matches;
    }

    /**
     * @param list<Node>           $nodes     Roots to traverse.
     * @param callable(Node): bool $predicate Predicate that selects matching descendants.
     * @return list<Node> Descendant nodes that match the predicate.
     */
    private function nodesMatching(array $nodes, callable $predicate): array
    {
        $matches = [];

        foreach ($nodes as $node) {
            $this->collectMatchingNodes($node, $predicate, $matches);
        }

        return $matches;
    }

    /**
     * @param callable(Node): bool $predicate Predicate that selects matching descendants.
     * @param list<Node>           $matches   Output list of matching descendant nodes.
     * @return void
     */
    private function collectMatchingNodes(Node $node, callable $predicate, array &$matches): void
    {
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            return;
        }

        if ($predicate($node)) {
            $matches[] = $node;
        }

        foreach ($this->childNodes($node) as $child) {
            $this->collectMatchingNodes($child, $predicate, $matches);
        }
    }

    /**
     * List direct child nodes that can be recursively traversed.
     *
     * @return list<Node>
     */
    private function childNodes(Node $node): array
    {
        $children = [];

        foreach ($node->getSubNodeNames() as $name) {
            $this->collectChildNodes($node->{$name}, $children);
        }

        return $children;
    }

    /**
     * Append traversable child nodes to the current collection.
     *
     * @param list<Node> $children
     * @return void
     */
    private function collectChildNodes(mixed $subNode, array &$children): void
    {
        if ($subNode instanceof Node) {
            $children[] = $subNode;
            return;
        }

        if (!is_array($subNode)) {
            return;
        }

        foreach ($subNode as $childSubNode) {
            $this->collectChildNodes($childSubNode, $children);
        }
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

    /**
     * Return the declaration kind for a class-like node.
     *
     * @return string One of class, interface, trait, or enum.
     */
    private function classLikeKind(Class_|Interface_|Trait_|Enum_ $node): string
    {
        return match (true) {
            $node instanceof Interface_ => 'interface',
            $node instanceof Trait_ => 'trait',
            $node instanceof Enum_ => 'enum',
            default => 'class',
        };
    }

    /**
     * Normalize string lists for case-insensitive comparisons.
     *
     * @param list<string> $values
     * @return list<string>
     */
    private function lowercaseList(array $values): array
    {
        return array_values(array_unique(array_map(
            static fn (string $name): string => strtolower($name),
            $values,
        )));
    }
}
