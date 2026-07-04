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
use GruffPhp\Rules\Modernisation\ModernisationNodeHelper;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;

/**
 * Flags a placeholder, generic, or numbered identifier - `$foo`, a lone `$data`, a `$user2` - because such
 * names obscure what a value represents and push the reader into the body to find out.
 *
 * Covers class-like, function, method, parameter, property, and sufficiently-used local names. Conventional
 * loop/exception names, magic and lifecycle methods, data providers, and single-parameter coercion helpers
 * are exempt; the placeholder/generic/ignored vocabulary is configurable. Advisory, medium confidence.
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
     * Array-iteration callables whose direct closure callbacks act like loop bodies.
     */
    private const ITERATION_CALLABLES = [
        'array_filter',
        'array_map',
        'array_walk',
        'usort',
        'uasort',
        'array_reduce',
        'array_any',
        'array_all',
        'array_find',
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
     * Describes the identifier-quality rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata, defaults, and options.
     */
    public function definition(): RuleDefinition
    {
        // Advisory-by-default identifier quality rule with its tunable placeholder/generic/ignore vocabulary.
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
            optionDescriptions: [
                'placeholderNames' => 'Names treated as obviously placeholder (foo, bar, baz, tmp, temp).',
                'genericTokens' => 'Tokens treated as generic when used as the whole identifier (data, entry, info, item).',
                'ignoredNames' => 'Names exempt from all checks (loop counters, exception variables, $_).',
                'minScopeReferences' => 'Minimum local-variable references before reporting generic names.',
                'loopBodyThreshold' => 'Body statement count at which generic names report for foreach loops and sole parameters of inline array-iteration callbacks.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'Short iteration bodies that use a conventional generic name ($entry, $item, $row) - foreach loops, or single-parameter closures passed directly to array-iteration callables such as array_filter.',
                    'mitigation' => 'Keep the body below loopBodyThreshold (default 4) statements, or add the name to options.ignoredNames.',
                ],
                [
                    'shape' => 'Single-parameter helpers whose role is intentionally generic (mixed → string converters, JSON-boundary helpers).',
                    'mitigation' => 'Single-parameter mixed-type helpers are skipped; rename multi-parameter helpers to describe their domain role.',
                ],
            ],
        );
    }

    /**
     * Reports placeholder, generic, and numbered identifiers across declarations and locals.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for low-quality identifiers.
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
     * Builds the shared finding inputs from rule settings.
     *
     * @param AnalysisUnit   $analysisUnit - Unit threaded into the context so every check reports against the same file.
     * @param RuleContext    $ruleContext - Source of the per-run settings and project-wide accepted abbreviations.
     * @param RuleDefinition $definition - This rule's definition, used to look up its configured option overrides.
     *
     * @return IdentifierFindingContext - Shared context for identifier finding checks.
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
     * Resolves the minimum local-variable reference count needed before reporting.
     *
     * @param RuleContext    $ruleContext - Carries the per-run option overrides for this threshold.
     * @param RuleDefinition $definition - This rule's definition, used to key into its option set.
     *
     * @return int - Minimum number of local variable reads.
     */
    private function minScopeReferences(RuleContext $ruleContext, RuleDefinition $definition): int
    {
        $minScopeOption = $ruleContext->settingsFor($definition)->option('minScopeReferences');
        // Clamp to at least 1 and fall back to 1 for a non-int override, so a single read can never suppress reporting.
        return is_int($minScopeOption) ? max(1, $minScopeOption) : 1;
    }

    /**
     * Resolves the foreach body-size threshold before generic loop variables report.
     *
     * @param RuleContext    $ruleContext - Carries the per-run option overrides for this threshold.
     * @param RuleDefinition $definition - This rule's definition, used to key into its option set.
     *
     * @return int - Minimum statement count for generic foreach loop-variable findings.
     */
    private function loopBodyThreshold(RuleContext $ruleContext, RuleDefinition $definition): int
    {
        $thresholdOption = $ruleContext->settingsFor($definition)->option('loopBodyThreshold');
        // Clamp to at least 1 and default to 4 for a non-int override, matching defaultOptions.loopBodyThreshold.
        return is_int($thresholdOption) ? max(1, $thresholdOption) : 4;
    }

    /**
     * Reports low-quality class, interface, trait, and enum names.
     *
     * @param IdentifierFindingContext $findingContext - Resolved vocabulary and unit shared across the per-node checks.
     *
     * @return list<Finding> - Findings for class-like identifiers.
     */
    private function classLikeFindings(IdentifierFindingContext $findingContext): array
    {
        $findings = [];

        $classLikes = NodeIndex::nodesOfAny(
            $findingContext->analysisUnit,
            [Class_::class, Interface_::class, Trait_::class, Enum_::class],
        );

        // Judge each class-like name in the file.
        foreach ($classLikes as $node) {
            /** @var Class_|Interface_|Trait_|Enum_ $node NodeIndex query is constrained to class-like classes. */
            $name = $node->name?->toString();
            // An anonymous class has no name to judge.
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
     * Reports low-quality function-like names, parameters, and local variables.
     *
     * @param IdentifierFindingContext $findingContext - Resolved vocabulary and unit shared across the per-scope checks.
     * @param int                      $minScopeReferences - Reference floor below which a local name is too rarely used to judge.
     * @param int                      $loopBodyThreshold - Foreach body-size above which a generic loop variable becomes reportable.
     *
     * @return list<Finding> - Findings for function-like identifier scopes.
     */
    private function functionLikeFindings(
        IdentifierFindingContext $findingContext,
        int $minScopeReferences,
        int $loopBodyThreshold,
    ): array {
        $findings             = [];
        $iterationCallbackIds = $this->iterationCallbackIds($findingContext->analysisUnit);

        // Judge each function-like scope, its parameters, and its locals.
        foreach ((new FunctionLikeScopeWalker())->scopes($findingContext->analysisUnit->statements) as $scope) {
            $function         = $scope->node;
            $functionFindings = $function instanceof ClassMethod || $function instanceof Function_
                ? $this->functionNameFindings($findingContext, $function)
                : [];

            array_push(
                $findings,
                ...$functionFindings,
                ...$this->parameterFindings($findingContext, $scope, $loopBodyThreshold, $iterationCallbackIds),
                ...$this->localVariableFindings($findingContext, $scope, $minScopeReferences, $loopBodyThreshold),
            );
        }

        return $findings;
    }

    /**
     * Reports a low-quality method or function name.
     *
     * @param IdentifierFindingContext $findingContext - Resolved vocabulary and unit shared across the per-node checks.
     * @param ClassMethod|Function_    $function - Named callable whose own declared name is judged here.
     *
     * @return list<Finding> - Empty when the function-like name is exempt or acceptable.
     */
    private function functionNameFindings(IdentifierFindingContext $findingContext, ClassMethod|Function_ $function): array
    {
        // Magic, lifecycle, and data-provider methods carry fixed names, so skip them.
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
     * Reports low-quality parameter and promoted-property names in one function-like scope.
     *
     * @param IdentifierFindingContext $findingContext - Resolved vocabulary and unit shared across the per-parameter checks.
     * @param FunctionLikeScope        $scope - Single function-like scope whose declared parameters are judged.
     * @param int                      $loopBodyThreshold - Body size at which generic iteration-callback parameters become reportable.
     * @param array<int, true>         $iterationCallbackIds - spl_object_id set of closures passed directly to array-iteration callables.
     *
     * @return list<Finding> - Findings for parameters and promoted properties.
     */
    private function parameterFindings(
        IdentifierFindingContext $findingContext,
        FunctionLikeScope $scope,
        int $loopBodyThreshold,
        array $iterationCallbackIds,
    ): array {
        $findings              = [];
        $symbol                = $this->symbol($scope);
        $skipGenericComplaints = $this->isGenericByPurposeHelper($scope)
            || $this->isShortIterationCallback($scope, $loopBodyThreshold, $iterationCallbackIds);

        // Weigh each declared parameter.
        foreach ($scope->node->params as $param) {
            // Skip anything without a plain string name.
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

            // In a generic-by-purpose helper or short iteration callback, a generic parameter name is fine.
            if ($skipGenericComplaints && ($finding->metadata['variant'] ?? null) === 'generic') {
                continue;
            }

            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * Detects a single-parameter, wide-typed, non-`void`-returning helper whose sole parameter
     * legitimately needs a generic name. The shape covers helpers like
     * `private static function stringValue(mixed $value): string` whose intent is "coerce anything
     * into the documented return type"; a generic parameter name (`$value`) is the right name there.
     *
     * @param FunctionLikeScope $scope - Scope whose underlying callable is matched against the coercion-helper shape.
     *
     * @return bool - true when the callable shape makes a generic single parameter name carry useful intent
     */
    private function isGenericByPurposeHelper(FunctionLikeScope $scope): bool
    {
        $node = $scope->node;
        // Only a named function or method can have the coercion-helper shape.
        if (!$node instanceof ClassMethod && !$node instanceof Function_) {
            return false;
        }

        // The shape needs exactly one parameter.
        if (count($node->params) !== 1) {
            return false;
        }

        // A void return means the helper coerces nothing, so the shape does not apply.
        if (ModernisationNodeHelper::typeName($node->returnType) === 'void') {
            return false;
        }

        $type = $node->params[0]->type;

        // A wide union parameter (three or more arms) is the coercion shape.
        if ($type instanceof Node\UnionType && count($type->types) >= 3) {
            return true;
        }

        $typeName = ModernisationNodeHelper::typeName($type);

        return $typeName === 'mixed' || $typeName === 'scalar';
    }

    /**
     * Indexes the closures and arrow functions passed directly as arguments to array-iteration callables.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose function calls are scanned for inline callbacks.
     *
     * @return array<int, true> - spl_object_id set of function-like nodes used as iteration callbacks.
     */
    private function iterationCallbackIds(AnalysisUnit $analysisUnit): array
    {
        $callbackIds = [];

        // Scan every function call for an array-iteration callable.
        foreach (NodeIndex::nodesOf($analysisUnit, FuncCall::class) as $call) {
            // Skip a call that is not one of the recognised iteration callables.
            if (!$call->name instanceof Name || !in_array(strtolower($call->name->toString()), self::ITERATION_CALLABLES, true)) {
                continue;
            }

            foreach ($call->args as $arg) {
                // Record a closure or arrow function passed directly as a callback argument.
                if ($arg instanceof Arg && ($arg->value instanceof Closure || $arg->value instanceof ArrowFunction)) {
                    $callbackIds[spl_object_id($arg->value)] = true;
                }
            }
        }

        return $callbackIds;
    }

    /**
     * Detects the sole-parameter, short-bodied iteration callback shape. The lone parameter of a closure
     * passed directly to an array-iteration callable plays the same role as a foreach value variable,
     * so the loopBodyThreshold escape hatch documented for loops applies to its generic name too.
     *
     * @param FunctionLikeScope $scope - Scope whose node may be an inline iteration callback.
     * @param int               $loopBodyThreshold - Body statement count at which generic names become reportable.
     * @param array<int, true>  $iterationCallbackIds - spl_object_id set of iteration-callback nodes.
     *
     * @return bool - True when the callback has exactly one parameter and its body stays below the loop threshold.
     */
    private function isShortIterationCallback(FunctionLikeScope $scope, int $loopBodyThreshold, array $iterationCallbackIds): bool
    {
        $node = $scope->node;
        // Only a registered one-parameter iteration callback qualifies.
        if (!isset($iterationCallbackIds[spl_object_id($node)]) || count($node->params) !== 1) {
            return false;
        }

        // An arrow function body is one expression; closures count their statement list like a foreach body.
        $statementCount = $node instanceof ArrowFunction ? 1 : count($node->stmts ?? []);

        return $statementCount < $loopBodyThreshold;
    }

    /**
     * Reports low-quality local variable names in one function-like scope.
     *
     * @param IdentifierFindingContext $findingContext - Resolved vocabulary and unit shared across the per-variable checks.
     * @param FunctionLikeScope        $scope - Single scope whose locals (excluding loop/catch vars) are judged.
     * @param int                      $minScopeReferences - Reference floor below which a local is too rarely used to judge.
     * @param int                      $loopBodyThreshold - Foreach body-size above which a generic loop variable becomes reportable.
     *
     * @return list<Finding> - Findings for local variables.
     */
    private function localVariableFindings(
        IdentifierFindingContext $findingContext,
        FunctionLikeScope $scope,
        int $minScopeReferences,
        int $loopBodyThreshold,
    ): array {
        $findings  = [];
        $symbol    = $this->symbol($scope);
        $loopVars  = IdentifierQualityScopeLocals::loopVariables($scope);
        $catchVars = IdentifierQualityScopeLocals::catchVariables($scope);

        // Judge each sufficiently-used local, leaving loop and catch variables to their own path.
        foreach (IdentifierQualityScopeLocals::localVariableNames($scope, $minScopeReferences, $loopVars + $catchVars) as $name => $variable) {
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
        // Also judge generic loop variables once their loop body grows past the threshold.
        foreach (IdentifierQualityScopeLocals::reportableLoopVariableNames($scope, $findingContext->genericTokens, $loopBodyThreshold) as $name => $variable) {
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
     * Reports low-quality declared property names.
     *
     * @param IdentifierFindingContext $findingContext - Resolved vocabulary and unit shared across the per-property checks.
     *
     * @return list<Finding> - Findings for property identifiers.
     */
    private function propertyFindings(IdentifierFindingContext $findingContext): array
    {
        $findings = [];

        // Check every declared property in the file.
        foreach (NodeIndex::nodesOf($findingContext->analysisUnit, Property::class) as $property) {
            // One declaration can name several properties, so check each.
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
     * Builds an identifier finding when the name is a placeholder, generic, or numbered variant.
     *
     * @param IdentifierFindingContext $identifierFindingContext - Resolved vocabulary, definition, and unit used to classify and stamp the finding.
     * @param Node                     $node - Declaration node whose start line anchors the finding.
     * @param string                   $kind - Human-readable identifier kind (class, method, parameter, variable, property) for the message.
     * @param string                   $name - Raw identifier text being judged, with any leading `$` kept for variable-style names.
     * @param string|null              $symbol - Enclosing symbol label for grouping, or null when no symbol applies.
     * @param list<string>|null        $ignoredNamesOverride - Optional ignored-name list for loop-variable checks.
     *
     * @return Finding|null - Identifier finding, or null when the name is acceptable/ignored.
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
        // An ignored or project-accepted name is exempt, so report nothing.
        if ($this->isIgnored($name, $ignoredNames, $identifierFindingContext->acceptedAbbreviations)) {
            return null;
        }

        $tokens = $identifierFindingContext->tokenizer->tokenize($name);
        // A name that tokenizes to nothing cannot be judged.
        if ($tokens === []) {
            return null;
        }

        $variant      = null;
        $matchedToken = null;
        $lowerName    = strtolower(ltrim($name, '$'));

        // Classify the name as a placeholder, an all-generic-token name, or a weak numbered variant.
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

        // None of the low-quality shapes matched, so the name is fine.
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
            remediation: 'Rename the identifier to describe its domain role or action. If the placeholder or generic token is intentional, add it to `rules.naming.identifier-quality.options.placeholderNames`, `genericTokens`, or `ignoredNames` in `.gruff-php.yaml`.',
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
     * Reports whether a name is exempt from all identifier-quality checks.
     *
     * @param string       $name - Identifier text to test against the exemption lists, matched case-insensitively.
     * @param list<string> $ignoredNames - Names exempt from all checks; the loop path may override the configured set.
     * @param list<string> $acceptedAbbreviations - Project-accepted abbreviations that should never be flagged.
     *
     * @return bool - True when the name should be skipped by this rule.
     */
    private function isIgnored(string $name, array $ignoredNames, array $acceptedAbbreviations): bool
    {
        $lowerName = strtolower($name);

        // A leading underscore marks a deliberate throwaway name.
        if (str_starts_with($name, '_')) {
            return true;
        }

        // An explicitly ignored name is exempt.
        if (in_array($lowerName, $ignoredNames, true)) {
            return true;
        }

        return in_array($lowerName, $acceptedAbbreviations, true);
    }

    /**
     * Reports whether every token is a configured generic token.
     *
     * @param list<string> $tokens - Identifier tokens to test.
     * @param list<string> $genericTokens - Configured tokens treated as generic when they stand alone.
     *
     * @return bool - True when every token is a configured generic token.
     */
    private function allTokensMatch(array $tokens, array $genericTokens): bool
    {
        // Every token must be generic for the whole name to read as generic.
        foreach ($tokens as $token) {
            if (!in_array($token, $genericTokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reports whether an identifier is a weak generic or placeholder name disambiguated only by a trailing number.
     *
     * @param string       $name - Raw identifier text, used to spot acronym-plus-number forms the tokens lose.
     * @param list<string> $tokens - Identifier tokens whose trailing element must be the disambiguating number.
     * @param list<string> $genericTokens - Configured generic tokens; a generic prefix makes the numbered name weak.
     * @param list<string> $placeholderNames - Configured placeholder names; a placeholder prefix also makes it weak.
     * @param list<string> $acceptedAbbreviations - Project-accepted abbreviations that exempt the prefix from the check.
     *
     * @return bool - True when the identifier is a weak numbered variant.
     */
    private function isNumberedIdentifier(
        string $name,
        array $tokens,
        array $genericTokens,
        array $placeholderNames,
        array $acceptedAbbreviations,
    ): bool {
        // A numbered name needs at least a prefix and a trailing digit token.
        if (count($tokens) < 2 || !ctype_digit($tokens[array_key_last($tokens)])) {
            return false;
        }

        $prefixTokens = array_slice($tokens, 0, -1);
        $prefix       = implode('', $prefixTokens);

        // An accepted-abbreviation prefix (e.g. a known acronym) is fine even when numbered.
        if (in_array($prefix, $acceptedAbbreviations, true)) {
            return false;
        }

        // Permit acronym-style identifiers that are only disambiguated by a trailing number.
        if (preg_match('/[A-Z]{2,}\d+$/', $name) === 1) {
            return false;
        }

        return in_array($prefix, $placeholderNames, true) || $this->allTokensMatch($prefixTokens, $genericTokens);
    }

    /**
     * Reports whether a callable is a magic, lifecycle, or data-provider method to skip.
     *
     * @param ClassMethod|Function_ $node - Callable whose name decides whether the rule exempts it from name judging.
     *
     * @return bool - True when framework lifecycle or data-provider methods should be skipped.
     */
    private function shouldSkipFunctionLike(ClassMethod|Function_ $node): bool
    {
        $name = $node->name->toString();

        // A magic method has a language-fixed name that cannot be renamed.
        if ($node instanceof ClassMethod && in_array($name, self::MAGIC_METHODS, true)) {
            return true;
        }

        // A framework lifecycle hook has a fixed name the framework calls by.
        if ($node instanceof ClassMethod && in_array($name, self::LIFECYCLE_METHODS, true)) {
            return true;
        }

        return str_starts_with($name, 'provide') || str_ends_with($name, 'Provider');
    }

    /**
     * Resolves the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope - Scope whose enclosing callable label is wanted for finding grouping.
     *
     * @return string - Named callable symbol or synthetic closure/arrow label.
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        // Named callables resolve to their declared symbol.
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }

    /**
     * Returns the declaration kind for a class-like node.
     *
     * @param Class_|Interface_|Trait_|Enum_ $node - Class-like node whose declaration keyword is wanted for the message.
     *
     * @return string - One of class, interface, trait, or enum.
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
     * Normalises a string list to lowercase for case-insensitive comparisons.
     *
     * @param list<string> $values - Raw configured names; case and duplicates are insignificant to the caller.
     *
     * @return list<string> - The same names lowercased and de-duplicated, re-indexed from zero.
     */
    private function lowercaseList(array $values): array
    {
        return array_values(array_unique(array_map(
            static fn (string $name): string => strtolower($name),
            $values,
        )));
    }
}
