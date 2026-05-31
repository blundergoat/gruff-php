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
                'loopBodyThreshold' => 'Foreach body statement count above which generic loop names report.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'Short loop bodies that use a conventional generic name ($entry, $item, $row) when iterating a payload.',
                    'mitigation' => 'Keep the loop body at or below loopBodyThreshold (default 4) statements, or add the name to options.ignoredNames.',
                ],
                [
                    'shape' => 'Single-parameter helpers whose role is intentionally generic (mixed → string converters, JSON-boundary helpers).',
                    'mitigation' => 'Single-parameter mixed-type helpers are skipped; rename multi-parameter helpers to describe their domain role.',
                ],
            ],
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
        // Union of the three identifier domains: class-likes, function-like scopes, and declared properties.
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
     * @param AnalysisUnit   $analysisUnit Unit threaded into the context so every check reports against the same file.
     * @param RuleContext    $ruleContext  Source of the per-run settings and project-wide accepted abbreviations.
     * @param RuleDefinition $definition    This rule's definition, used to look up its configured option overrides.
     *
     * @return IdentifierFindingContext Shared context for identifier finding checks.
     */
    private function findingContext(
        AnalysisUnit $analysisUnit,
        RuleContext $ruleContext,
        RuleDefinition $definition,
    ): IdentifierFindingContext {
        $settings = $ruleContext->settingsFor($definition);
        // Resolve the configured vocabulary once (lowercased) so per-identifier checks stay allocation-free.
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
     * @param RuleContext    $ruleContext Carries the per-run option overrides for this threshold.
     * @param RuleDefinition $definition  This rule's definition, used to key into its option set.
     *
     * @return int Minimum number of local variable reads.
     */
    private function minScopeReferences(RuleContext $ruleContext, RuleDefinition $definition): int
    {
        $minScopeOption = $ruleContext->settingsFor($definition)->option('minScopeReferences');
        // Clamp to at least 1 and fall back to 1 for a non-int override, so a single read can never suppress reporting.
        return is_int($minScopeOption) ? max(1, $minScopeOption) : 1;
    }

    /**
     * Resolve the foreach body-size threshold before generic loop variables report.
     *
     * @param RuleContext    $ruleContext Carries the per-run option overrides for this threshold.
     * @param RuleDefinition $definition  This rule's definition, used to key into its option set.
     *
     * @return int Minimum statement count for generic foreach loop-variable findings.
     */
    private function loopBodyThreshold(RuleContext $ruleContext, RuleDefinition $definition): int
    {
        $thresholdOption = $ruleContext->settingsFor($definition)->option('loopBodyThreshold');
        // Clamp to at least 1 and default to 4 for a non-int override, matching defaultOptions.loopBodyThreshold.
        return is_int($thresholdOption) ? max(1, $thresholdOption) : 4;
    }

    /**
     * Find low-quality class, interface, trait, and enum names.
     *
     * @param IdentifierFindingContext $findingContext Resolved vocabulary and unit shared across the per-node checks.
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

        // Hand back the class-like naming findings gathered for this unit.
        return $findings;
    }

    /**
     * Find low-quality function-like names, parameters, and local variables.
     *
     * @param IdentifierFindingContext $findingContext     Resolved vocabulary and unit shared across the per-scope checks.
     * @param int                      $minScopeReferences Reference floor below which a local name is too rarely used to judge.
     * @param int                      $loopBodyThreshold  Foreach body-size above which a generic loop variable becomes reportable.
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

        // Hand back the combined name, parameter, and local-variable findings for every function-like scope.
        return $findings;
    }

    /**
     * Find a low-quality method or function name.
     *
     * @param IdentifierFindingContext $findingContext Resolved vocabulary and unit shared across the per-node checks.
     * @param ClassMethod|Function_    $function       Named callable whose own declared name is judged here.
     *
     * @return list<Finding> Empty when the function-like name is exempt or acceptable.
     */
    private function functionNameFindings(IdentifierFindingContext $findingContext, ClassMethod|Function_ $function): array
    {
        if ($this->shouldSkipFunctionLike($function)) {
            // Magic, lifecycle, and data-provider names are exempt by contract, so skip without judging the name.
            return [];
        }

        $finding = $this->finding(
            identifierFindingContext: $findingContext,
            node:                     $function,
            kind:                     $function instanceof ClassMethod ? 'method' : 'function',
            name:                     $function->name->toString(),
            symbol:                   CyclomaticComplexityRule::resolveSymbol($function),
        );

        // One finding when the name is low-quality, otherwise an empty list for the caller to splat.
        return $finding instanceof Finding ? [$finding] : [];
    }

    /**
     * Find low-quality parameter and promoted-property names in one function-like scope.
     *
     * @param IdentifierFindingContext $findingContext Resolved vocabulary and unit shared across the per-parameter checks.
     * @param FunctionLikeScope        $scope          Single function-like scope whose declared parameters are judged.
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

        // Hand back the parameter and promoted-property findings collected for this scope.
        return $findings;
    }

    /**
     * Detect a single-parameter, wide-typed, non-`void`-returning helper whose sole parameter
     * legitimately needs a generic name. The shape covers helpers like
     * `private static function stringValue(mixed $value): string` whose intent is "coerce anything
     * into the documented return type"; a generic parameter name (`$value`) is the right name there.
     *
     * @param FunctionLikeScope $scope Scope whose underlying callable is matched against the coercion-helper shape.
     */
    private function isGenericByPurposeHelper(FunctionLikeScope $scope): bool
    {
        $node = $scope->node;
        if (!$node instanceof ClassMethod && !$node instanceof Function_) {
            // Closures and arrow functions carry no name-able contract, so they never qualify for the carve-out.
            return false;
        }

        if (count($node->params) !== 1) {
            // The carve-out only excuses the lone parameter of a converter; multi-parameter helpers must name each role.
            return false;
        }

        if (ModernisationNodeHelper::typeName($node->returnType) === 'void') {
            // A void return means there is no documented output type for a generic input name to stand in for.
            return false;
        }

        $type = $node->params[0]->type;

        if ($type instanceof Node\UnionType && count($type->types) >= 3) {
            // A 3+ member union is wide enough that "coerce anything" is the real intent, so a generic name fits.
            return true;
        }

        $typeName = ModernisationNodeHelper::typeName($type);

        // mixed/scalar inputs are the canonical "accept anything" converters where a generic parameter name is correct.
        return $typeName === 'mixed' || $typeName === 'scalar';
    }

    /**
     * Find low-quality local variable names in one function-like scope.
     *
     * @param IdentifierFindingContext $findingContext     Resolved vocabulary and unit shared across the per-variable checks.
     * @param FunctionLikeScope        $scope              Single scope whose locals (excluding loop/catch vars) are judged.
     * @param int                      $minScopeReferences Reference floor below which a local is too rarely used to judge.
     * @param int                      $loopBodyThreshold  Foreach body-size above which a generic loop variable becomes reportable.
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

        // Hand back the plain-local findings plus the long-body loop-variable findings for this scope.
        return $findings;
    }

    /**
     * Find low-quality declared property names.
     *
     * @param IdentifierFindingContext $findingContext Resolved vocabulary and unit shared across the per-property checks.
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

        // Hand back the declared-property naming findings gathered for this unit.
        return $findings;
    }

    /**
     * @param IdentifierFindingContext $identifierFindingContext Resolved vocabulary, definition, and unit used to classify and stamp the finding.
     * @param Node                     $node                     Declaration node whose start line anchors the finding.
     * @param string                   $kind                     Human-readable identifier kind (class, method, parameter, variable, property) for the message.
     * @param string                   $name                     Raw identifier text being judged, with any leading `$` kept for variable-style names.
     * @param string|null              $symbol                   Enclosing symbol label for grouping, or null when no symbol applies.
     * @param list<string>|null        $ignoredNamesOverride     Optional ignored-name list for loop-variable checks.
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
            // Conventional and project-accepted names are exempt, so report nothing for them.
            return null;
        }

        $tokens = $identifierFindingContext->tokenizer->tokenize($name);
        if ($tokens === []) {
            // A name that tokenizes to nothing (e.g. `$_`) carries no words to judge.
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
            // No placeholder, generic, or numbered variant matched, so the name communicates intent and passes.
            return null;
        }

        // Emit the finding, stamping the matched variant and tokens into metadata for downstream filtering.
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
     * @param string       $name                  Identifier text to test against the exemption lists, matched case-insensitively.
     * @param list<string> $ignoredNames          Names exempt from all checks; the loop path may override the configured set.
     * @param list<string> $acceptedAbbreviations Project-accepted abbreviations that should never be flagged.
     *
     * @return bool True when the name should be skipped by this rule.
     */
    private function isIgnored(string $name, array $ignoredNames, array $acceptedAbbreviations): bool
    {
        $lowerName = strtolower($name);

        if (str_starts_with($name, '_')) {
            // A leading underscore signals a deliberately unused or convention-discard name, so never flag it.
            return true;
        }

        if (in_array($lowerName, $ignoredNames, true)) {
            // Name is on the ignored list (loop counters, exception vars, $_), so it is exempt.
            return true;
        }

        // Otherwise it is exempt only if the project has accepted it as a known abbreviation.
        return in_array($lowerName, $acceptedAbbreviations, true);
    }

    /**
     * @param list<string> $tokens        Identifier tokens to test.
     * @param list<string> $genericTokens Configured tokens treated as generic when they stand alone.
     *
     * @return bool True when every token is a configured generic token.
     */
    private function allTokensMatch(array $tokens, array $genericTokens): bool
    {
        foreach ($tokens as $token) {
            if (!in_array($token, $genericTokens, true)) {
                // One non-generic token is enough to give the whole identifier meaning, so it is not all-generic.
                return false;
            }
        }

        // Every token was generic (an empty token list also satisfies this), so the identifier reads as generic.
        return true;
    }

    /**
     * @param string       $name                  Raw identifier text, used to spot acronym-plus-number forms the tokens lose.
     * @param list<string> $tokens                Identifier tokens whose trailing element must be the disambiguating number.
     * @param list<string> $genericTokens         Configured generic tokens; a generic prefix makes the numbered name weak.
     * @param list<string> $placeholderNames      Configured placeholder names; a placeholder prefix also makes it weak.
     * @param list<string> $acceptedAbbreviations Project-accepted abbreviations that exempt the prefix from the check.
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
            // Without a prefix and a trailing all-digit token there is no "name + counter" shape to flag.
            return false;
        }

        $prefixTokens = array_slice($tokens, 0, -1);
        $prefix       = implode('', $prefixTokens);

        if (in_array($prefix, $acceptedAbbreviations, true)) {
            // An accepted-abbreviation prefix (e.g. an approved acronym) is allowed to carry a numeric suffix.
            return false;
        }

        // Permit acronym-style identifiers that are only disambiguated by a trailing number.
        if (preg_match('/[A-Z]{2,}\d+$/', $name) === 1) {
            // Trailing-number acronyms (e.g. UTF8) are intentional disambiguation, not a lazy counter.
            return false;
        }

        // Weak only when the prefix itself carries no intent: a known placeholder, or an all-generic prefix.
        return in_array($prefix, $placeholderNames, true) || $this->allTokensMatch($prefixTokens, $genericTokens);
    }

    /**
     * @param ClassMethod|Function_ $node Callable whose name decides whether the rule exempts it from name judging.
     *
     * @return bool True when framework lifecycle or data-provider methods should be skipped.
     */
    private function shouldSkipFunctionLike(ClassMethod|Function_ $node): bool
    {
        $name = $node->name->toString();

        if ($node instanceof ClassMethod && in_array($name, self::MAGIC_METHODS, true)) {
            // Magic methods have language-mandated names the author cannot choose, so never judge them.
            return true;
        }

        if ($node instanceof ClassMethod && in_array($name, self::LIFECYCLE_METHODS, true)) {
            // Framework lifecycle hooks (setUp, configure, execute) have fixed names the caller must keep.
            return true;
        }

        // Data-provider methods follow the provide*/<*>Provider convention PHPUnit requires, so exempt them too.
        return str_starts_with($name, 'provide') || str_ends_with($name, 'Provider');
    }

    /**
     * @param FunctionLikeScope   $scope              Scope whose declared locals are the candidate set.
     * @param int                 $minScopeReferences Read-count floor; locals used fewer times are too transient to judge.
     * @param array<string, true> $excludedNames      Names already exempted by surrounding rule logic.
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

        // Hand back only the locals that survived exclusion and met the read-count floor.
        return $variables;
    }

    /**
     * @param FunctionLikeScope $scope Scope to scan for loop-induction and foreach key/value variables.
     *
     * @return array<string, true> Variables introduced by loop constructs.
     */
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

        // Hand back the loop-introduced names so the local-variable pass can exclude them from generic judging.
        return $variables;
    }

    /**
     * @param FunctionLikeScope $scope Scope to scan for catch-clause exception variables.
     *
     * @return array<string, true> Variables introduced by catch clauses.
     */
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
        $walker = new IdentifierAstWalker();
        foreach ($nodes as $node) {
            foreach ($walker->nodesMatching([$node], static fn (Node $candidate): bool => $candidate instanceof Variable) as $variable) {
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
