<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PhpParser\Modifiers;
use LogicException;

/**
 * Flags a bool-returning function or method, or a typed bool property or parameter, whose name does not read
 * as a yes/no question - so `active()` should become `isActive()` and a `$ready` flag should read as a predicate.
 *
 * Accepts predicate prefixes, clear whole-name state adjectives for typed properties and parameters,
 * multi-token state suffixes, subject-first proposition verbs, and exact caller-visible names. Negative
 * flags remain owned by the negative-boolean rule. Advisory, medium confidence.
 */
final readonly class BooleanPrefixRule implements RuleInterface
{
    /** Full configuration path for intentional caller-visible Boolean names. */
    private const ACCEPTED_NAMES_CONFIGURATION_KEY = 'rules.naming.boolean-prefix.options.acceptedBooleanNames';

    /**
     * Minimum token count for a subject, proposition verb, and trailing context.
     */
    private const MIN_PROPOSITION_TOKENS = 3;

    /**
     * Minimum token count that distinguishes a state suffix from a whole-name adjective.
     */
    private const MIN_STATE_SUFFIX_TOKENS = 2;

    /**
     * Stable identifier for the boolean prefix rule.
     */
    public const ID = 'naming.boolean-prefix';

    /**
     * Prefixes that make boolean-returning callables read as predicates.
     */
    private const GOOD_PREFIXES = [
        'is', 'has', 'can', 'should', 'will', 'was', 'does', 'all',
        'accepts', 'allows', 'contains', 'disables', 'enables', 'excludes',
        'extends', 'includes', 'invokes', 'matches', 'permits', 'refers',
        'requires', 'returns', 'looks', 'supports', 'touches', 'uses',
    ];

    /**
     * State adjectives that are clear for typed boolean properties and parameters.
     */
    private const STATE_ADJECTIVES = [
        'active', 'enabled', 'disabled', 'applicable', 'generated', 'interactive',
        'emitted', 'visible', 'available', 'valid', 'strict', 'silent',
        'resolved', 'limited', 'printable',
    ];

    /**
     * State words accepted only at the end of identifiers containing multiple tokens.
     */
    private const STATE_SUFFIXES = ['requested', 'present', 'enabled', 'allowed'];

    /**
     * Verbs accepted only between a subject token and trailing predicate or context tokens.
     */
    private const PROPOSITION_VERBS = ['requires'];

    /**
     * Exact boolean identifier names accepted as-is, regardless of prefix.
     *
     * Unlike protocol acronyms there is no universal set of bare boolean names
     * that earns its place across every codebase, so the default is empty and a
     * project appends its own domain vocabulary (e.g. a public `valid(): bool`
     * accessor it does not want renamed to `isValid()`). The escape hatch exists
     * so a finding on a public, caller-visible boolean name can be cleared with
     * config rather than a breaking rename, which is the non-breaking resolution
     * this hatch is here to provide. Matching is whole-name and case-insensitive.
     *
     * @var list<string>
     */
    private const DEFAULT_ACCEPTED_BOOLEAN_NAMES = [];

    /**
     * Describes the boolean-method-prefix rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Boolean method prefix',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  [
                'allowedPrefixes' => self::GOOD_PREFIXES,
                'stateAdjectiveAllowlist' => self::STATE_ADJECTIVES,
                'stateSuffixAllowlist' => self::STATE_SUFFIXES,
                'propositionVerbAllowlist' => self::PROPOSITION_VERBS,
                'acceptedBooleanNames' => self::DEFAULT_ACCEPTED_BOOLEAN_NAMES,
                'includePublicApi' => true,
            ],
            description:        'Accepts predicate prefixes, property/parameter state adjectives, multi-token state suffixes, subject-first propositions, and exact compatibility names while flagging vague Boolean identifiers.',
            optionDescriptions: [
                'allowedPrefixes' => 'Leading predicate words accepted at camelCase or snake_case word boundaries.',
                'stateAdjectiveAllowlist' => 'Exact whole Boolean names accepted for typed properties and parameters only.',
                'stateSuffixAllowlist' => 'Final whole tokens accepted on Boolean names containing at least two tokens across methods, functions, properties, and parameters.',
                'propositionVerbAllowlist' => 'Internal whole verbs accepted only with a subject token before and a context token after.',
                'acceptedBooleanNames' => 'Exact case-insensitive Boolean names accepted across receivers for compatibility.',
                'includePublicApi' => 'Whether to inspect public/protected methods, properties, named functions, and their caller-visible parameters; false limits findings to private/local declarations.',
            ],
        );
    }

    /**
     * Reports bool-returning callables and typed bool properties or parameters that lack a predicate-style name.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for poorly named boolean callables.
     * @throws LogicException When programmatic rule settings provide a non-boolean includePublicApi value.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition       = $this->definition();
        $settings         = $ruleContext->settingsFor($definition);
        $prefixes         = $settings->stringListOption('allowedPrefixes');
        $stateAdjectives  = array_map(static fn (string $name): string => strtolower($name), $settings->stringListOption('stateAdjectiveAllowlist'));
        $stateSuffixes    = array_map(static fn (string $name): string => strtolower($name), $settings->stringListOption('stateSuffixAllowlist'));
        $propositionVerbs = array_map(static fn (string $name): string => strtolower($name), $settings->stringListOption('propositionVerbAllowlist'));
        $acceptedNames    = array_map(static fn (string $name): string => strtolower($name), $settings->stringListOption('acceptedBooleanNames'));
        $includePublicApi = $settings->options['includePublicApi'] ?? true;
        // Programmatic RuleSettings callers receive the same strict type contract as YAML configuration.
        if (!is_bool($includePublicApi)) {
            throw new LogicException('Option "includePublicApi" must be a boolean.');
        }

        $findings = [];

        // Judge each function-like scope for its callable name and its bool parameters.
        foreach ((new FunctionLikeScopeWalker())->scopes($analysisUnit->statements) as $scope) {
            $node                       = $scope->node;
            $symbol                     = $this->symbol($scope);
            $isCallerVisibleApiExcluded = !$includePublicApi && $this->isCallerVisibleScope($node);
            $functionLikeFindings       = !$isCallerVisibleApiExcluded
                && ($node instanceof ClassMethod || $node instanceof Function_)
                ? $this->functionLikeFindings(
                    definition:       $definition,
                    analysisUnit:     $analysisUnit,
                    node:             $node,
                    symbol:           $symbol,
                    prefixes:         $prefixes,
                    stateSuffixes:    $stateSuffixes,
                    propositionVerbs: $propositionVerbs,
                    acceptedNames:    $acceptedNames,
                )
                : [];
            $parameterFindings = $isCallerVisibleApiExcluded
                ? []
                : $this->parameterFindings(
                    definition:       $definition,
                    analysisUnit:     $analysisUnit,
                    scope:            $scope,
                    prefixes:         $prefixes,
                    stateAdjectives:  $stateAdjectives,
                    stateSuffixes:    $stateSuffixes,
                    propositionVerbs: $propositionVerbs,
                    acceptedNames:    $acceptedNames,
                );

            array_push(
                $findings,
                ...$functionLikeFindings,
                ...$parameterFindings,
            );
        }

        // Also judge every typed boolean property declared in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            // Private/local-only mode excludes public and protected stored API state.
            if (!$includePublicApi && !$property->isPrivate()) {
                continue;
            }

            // Skip a property that is not typed bool.
            if (!$this->isBoolType($property->type)) {
                continue;
            }

            // One declaration can name several properties, so check each in turn.
            foreach ($property->props as $prop) {
                $name = $prop->name->toString();
                // A predicate-style or negative-flag name already reads clearly.
                if ($this->hasBooleanStyleName(
                    name:             $name,
                    prefixes:         $prefixes,
                    stateAdjectives:  $stateAdjectives,
                    stateSuffixes:    $stateSuffixes,
                    propositionVerbs: $propositionVerbs,
                    acceptedNames:    $acceptedNames,
                ) || $this->hasNegativeFlagName($name)) {
                    continue;
                }

                $findings[] = $this->identifierFinding(
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    node:         $prop,
                    kind:         'property',
                    name:         $name,
                    symbol:       '$' . $name,
                    action:       $property->isPrivate() ? RemediationAction::Apply : RemediationAction::Consider,
                );
            }
        }

        return $findings;
    }

    /**
     * Reports a bool-returning function or method whose name is not predicate-style.
     *
     * @param RuleDefinition        $definition       - Rule metadata stamped onto any finding produced here.
     * @param AnalysisUnit          $analysisUnit     - Parsed unit supplying the display path and line numbers.
     * @param ClassMethod|Function_ $node             - Callable whose return type and name are checked.
     * @param string                $symbol           - Human-readable symbol used as the finding subject.
     * @param list<string>          $prefixes         - Configured predicate prefixes.
     * @param list<string>          $stateSuffixes    - Lowercased state words accepted only as multi-token suffixes.
     * @param list<string>          $propositionVerbs - Lowercased verbs accepted between subject and context tokens.
     * @param list<string>          $acceptedNames    - Lowercased exact names accepted as-is.
     *
     * @return list<Finding> - Findings for bool-returning callables.
     */
    private function functionLikeFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        ClassMethod|Function_ $node,
        string $symbol,
        array $prefixes,
        array $stateSuffixes,
        array $propositionVerbs,
        array $acceptedNames,
    ): array
    {
        if (!$this->isBoolType($node->getReturnType())) {
            // Non-boolean callables are out of scope; nothing to flag.
            return [];
        }

        $name = $node->name->toString();
        // Accept only the configured prefix, token grammar, or exact public-name hatches.
        if ($this->hasAllowedPrefix($name, $prefixes)
            || $this->hasMultiTokenStateSuffix($name, $stateSuffixes)
            || $this->hasSubjectFirstProposition($name, $propositionVerbs)
            || $this->isAcceptedBooleanName($name, $acceptedNames)
        ) {
            // Name already reads as a predicate or is on the accepted allowlist, so it is clear.
            return [];
        }

        $action = $this->functionLikeAction($node);

        return [
            new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s returns bool but does not use a recognised boolean prefix.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: $action === RemediationAction::Apply
                    ? 'Rename to use a boolean prefix, e.g. isActive(), hasPermission().'
                    : 'Rename to use a boolean prefix, e.g. isActive(), hasPermission(). If a project-specific prefix is intentional, add it to `rules.naming.boolean-prefix.options.allowedPrefixes`; to accept a caller-visible name without renaming it, add the exact name to `rules.naming.boolean-prefix.options.acceptedBooleanNames` in `.gruff-php.yaml`.',
                metadata:    $action->metadata(self::ACCEPTED_NAMES_CONFIGURATION_KEY),
            ),
        ];
    }

    /**
     * Reports typed bool parameters that lack a predicate prefix or approved state adjective.
     *
     * @param RuleDefinition    $definition       - Rule metadata stamped onto any finding produced here.
     * @param AnalysisUnit      $analysisUnit     - Parsed unit supplying the display path and line numbers.
     * @param FunctionLikeScope $scope            - Scope whose declared parameters are inspected.
     * @param list<string>      $prefixes         - Configured predicate prefixes.
     * @param list<string>      $stateAdjectives  - Configured state-adjective names.
     * @param list<string>      $stateSuffixes    - Lowercased state words accepted only as multi-token suffixes.
     * @param list<string>      $propositionVerbs - Lowercased verbs accepted between subject and context tokens.
     * @param list<string>      $acceptedNames    - Lowercased exact names accepted as-is.
     *
     * @return list<Finding> - Findings for bool parameters.
     */
    private function parameterFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        array $prefixes,
        array $stateAdjectives,
        array $stateSuffixes,
        array $propositionVerbs,
        array $acceptedNames,
    ): array {
        $findings = [];
        $symbol   = $this->symbol($scope);

        // Weigh each declared parameter.
        foreach ($scope->node->params as $param) {
            // Skip anything that is not a plainly named bool parameter.
            if (!$this->isBoolType($param->type) || !$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $name = $param->var->name;
            // A predicate-style or negative-flag name already reads clearly.
            if ($this->hasBooleanStyleName(
                name:             $name,
                prefixes:         $prefixes,
                stateAdjectives:  $stateAdjectives,
                stateSuffixes:    $stateSuffixes,
                propositionVerbs: $propositionVerbs,
                acceptedNames:    $acceptedNames,
            ) || $this->hasNegativeFlagName($name)) {
                continue;
            }

            $findings[] = $this->identifierFinding(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                node:         $param,
                kind:         $param->flags === 0 ? 'parameter' : 'property',
                name:         $name,
                symbol:       $symbol,
                action:       $this->parameterAction($scope, $param),
            );
        }

        return $findings;
    }

    /**
     * Builds a finding for a typed boolean property or parameter.
     *
     * @param RuleDefinition $definition - Rule metadata stamped onto the finding.
     * @param AnalysisUnit   $analysisUnit - Parsed unit supplying the display path and start line.
     * @param Node           $node - Property or parameter node the finding points at.
     * @param string         $kind - Identifier kind label, either "property" or "parameter".
     * @param string         $name - Identifier name without the leading dollar sign.
     * @param string|null    $symbol - Owning callable symbol, or null for a bare property.
     * @param RemediationAction $action - Direct fix for private/local declarations, or review for caller-visible declarations.
     *
     * @return Finding - Finding for a boolean identifier without clear predicate naming.
     */
    private function identifierFinding(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        Node $node,
        string $kind,
        string $name,
        ?string $symbol,
        RemediationAction $action,
    ): Finding {
        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s "$%s" is typed bool but does not use a boolean prefix or approved state adjective.', ucfirst($kind), $name),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: $action === RemediationAction::Apply
                ? 'Rename to use a boolean prefix such as is/has/can or configure stateAdjectiveAllowlist for a clear local state adjective.'
                : 'Rename to use a boolean prefix such as is/has/can, configure stateAdjectiveAllowlist for clear state adjectives, or add a caller-visible name to acceptedBooleanNames to accept it without renaming.',
            metadata:    array_merge(
                [
                    'identifierKind' => $kind,
                    'identifierName' => $name,
                ],
                $action->metadata(self::ACCEPTED_NAMES_CONFIGURATION_KEY),
            ),
        );
    }

    /**
     * Classifies a named Boolean callable by whether renaming can break callers.
     *
     * @param ClassMethod|Function_ $node - Method or function that produced the finding.
     *
     * @return RemediationAction - APPLY for private methods; CONSIDER for public/protected methods and functions.
     */
    private function functionLikeAction(ClassMethod|Function_ $node): RemediationAction
    {
        return $node instanceof ClassMethod && $node->isPrivate()
            ? RemediationAction::Apply
            : RemediationAction::Consider;
    }

    /**
     * Classifies a Boolean parameter using promotion flags and its owning callable.
     *
     * @param FunctionLikeScope $scope - Callable that owns the parameter.
     * @param Param             $param - Parameter declaration, including promoted-property flags.
     *
     * @return RemediationAction - APPLY for private promoted state and local/private callables; otherwise CONSIDER.
     */
    private function parameterAction(FunctionLikeScope $scope, Param $param): RemediationAction
    {
        // A promoted parameter is stored state, so its own declared visibility decides compatibility risk.
        if ($param->isPromoted()) {
            return ($param->flags & Modifiers::PRIVATE) !== 0
                ? RemediationAction::Apply
                : RemediationAction::Consider;
        }

        return $scope->node instanceof Closure
            || $scope->node instanceof ArrowFunction
            || ($scope->node instanceof ClassMethod && $scope->node->isPrivate())
                ? RemediationAction::Apply
                : RemediationAction::Consider;
    }

    /**
     * Reports whether a function-like declaration exposes caller-visible names.
     *
     * Named functions are always callable API. Public and protected methods are visible to callers or
     * subclasses, while private methods, closures, and arrow functions remain local implementation details.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Function-like declaration to classify.
     *
     * @return bool - True for named functions and non-private methods; false for private/local callables.
     */
    private function isCallerVisibleScope(ClassMethod|Function_|Closure|ArrowFunction $node): bool
    {
        // Named functions expose their name and parameters directly to callers.
        if ($node instanceof Function_) {
            return true;
        }

        return $node instanceof ClassMethod && !$node->isPrivate();
    }

    /**
     * Reports whether a declaration type is bool or nullable bool.
     *
     * @param Node|null $type - Declared type node to classify, or null when the declaration is untyped.
     *
     * @return bool - True when the type resolves to bool, including ?bool and bool|null.
     */
    private function isBoolType(?Node $type): bool
    {
        if ($type instanceof NullableType) {
            // Unwrap `?T` and classify the inner type so `?bool` counts as bool.
            return $this->isBoolType($type->type);
        }

        if ($type instanceof Identifier) {
            // True only for the built-in scalar `bool` keyword, matched case-insensitively.
            return $type->toLowerString() === 'bool';
        }

        if ($type instanceof Name) {
            // A name token spelling `bool` (e.g. a leading-slash form) still resolves to the scalar.
            return strtolower($type->toString()) === 'bool';
        }

        // A union type must be unwrapped before it can be judged.
        if ($type instanceof UnionType) {
            $nonNull = array_values(array_filter(
                $type->types,
                static fn (Node $node): bool => !($node instanceof Identifier && $node->toLowerString() === 'null'),
            ));

            // A union is bool only when its sole non-null member is itself bool, i.e. exactly `bool|null`.
            return count($nonNull) === 1 && $this->isBoolType($nonNull[0]);
        }

        // Any other type shape (other scalars, classes, intersection, untyped) is not bool.
        return false;
    }

    /**
     * Reports whether a typed boolean identifier already reads clearly.
     *
     * @param string       $name             - Identifier name to test, matched case-insensitively.
     * @param list<string> $prefixes         - Configured predicate prefixes.
     * @param list<string> $stateAdjectives  - Configured state-adjective names.
     * @param list<string> $stateSuffixes    - Lowercased state words accepted only as multi-token suffixes.
     * @param list<string> $propositionVerbs - Lowercased verbs accepted between subject and context tokens.
     * @param list<string> $acceptedNames    - Lowercased exact names accepted as-is.
     *
     * @return bool - True when the identifier is allowed.
     */
    private function hasBooleanStyleName(
        string $name,
        array $prefixes,
        array $stateAdjectives,
        array $stateSuffixes,
        array $propositionVerbs,
        array $acceptedNames,
    ): bool {
        return $this->hasAllowedPrefix($name, $prefixes)
            || in_array(strtolower($name), $stateAdjectives, true)
            || $this->hasMultiTokenStateSuffix($name, $stateSuffixes)
            || $this->hasSubjectFirstProposition($name, $propositionVerbs)
            || $this->isAcceptedBooleanName($name, $acceptedNames);
    }

    /**
     * Reports whether a multi-token identifier ends with one configured state word.
     *
     * @param string       $name          - Identifier whose tokenizer-defined final word is inspected.
     * @param list<string> $stateSuffixes - Lowercased whole tokens accepted in the final position.
     *
     * @return bool - True only for a two-or-more-token name ending in an accepted state token.
     */
    private function hasMultiTokenStateSuffix(string $name, array $stateSuffixes): bool
    {
        $tokens = (new IdentifierTokenizer())->tokenize($name);

        // A single token belongs to whole-name adjective configuration, never suffix matching.
        if (count($tokens) < self::MIN_STATE_SUFFIX_TOKENS) {
            return false;
        }

        return in_array($tokens[count($tokens) - 1], $stateSuffixes, true);
    }

    /**
     * Reports whether a subject-first name contains a configured verb with context on both sides.
     *
     * @param string       $name             - Identifier whose tokenizer-defined words are inspected in order.
     * @param list<string> $propositionVerbs - Lowercased whole verbs accepted inside the proposition.
     *
     * @return bool - True when an accepted verb has at least one subject and one trailing context token.
     */
    private function hasSubjectFirstProposition(string $name, array $propositionVerbs): bool
    {
        $tokens = (new IdentifierTokenizer())->tokenize($name);

        // Subject-first grammar needs a leading subject, an internal verb, and trailing context.
        if (count($tokens) < self::MIN_PROPOSITION_TOKENS) {
            return false;
        }

        $internalTokens = array_slice($tokens, 1, -1);

        return array_intersect($internalTokens, $propositionVerbs) !== [];
    }

    /**
     * Reports whether a boolean identifier is on the exact accepted-name allowlist.
     *
     * The allowlist holds whole identifier names a project has chosen to keep as
     * they are, typically a caller-visible name a rename would break, so the
     * comparison is whole-name and case-insensitive, never a prefix match. The
     * caller supplies the names already lowercased.
     *
     * @param string       $name - Identifier name to match, compared whole and case-insensitively.
     * @param list<string> $acceptedNames - Lowercased exact names accepted as-is.
     *
     * @return bool - True when the name matches an accepted boolean name.
     */
    private function isAcceptedBooleanName(string $name, array $acceptedNames): bool
    {
        return in_array(strtolower($name), $acceptedNames, true);
    }

    /**
     * Reports whether a callable name starts with a configured predicate prefix at a word boundary.
     *
     * @param string       $name - Callable name to test; a prefix only counts when a word boundary follows it.
     * @param list<string> $prefixes - Configured predicate prefixes.
     *
     * @return bool - True when the name has an allowed prefix followed by a word boundary.
     */
    private function hasAllowedPrefix(string $name, array $prefixes): bool
    {
        // Try each configured predicate prefix.
        foreach ($prefixes as $prefix) {
            // Skip a prefix the name does not start with.
            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            if (strlen($name) === strlen($prefix)) {
                // Name equals the prefix exactly (e.g. `is`), which reads as a predicate on its own.
                return true;
            }

            $nextChar = $name[strlen($prefix)];
            if (($nextChar >= 'A' && $nextChar <= 'Z') || $nextChar === '_') {
                // An uppercase char marks a camelCase boundary (`isReady`) and an underscore a snake_case
                // boundary (`is_ready`), so either way the prefix is a real word; a lowercase char (`isolate`) is not.
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a name starts with a negative-flag prefix owned by the negative-boolean rule.
     *
     * @param string $name - Identifier name to test; the negative prefix must be followed by a word boundary.
     *
     * @return bool - True when the name starts with a configured negative prefix, so NegativeBooleanRule owns it.
     */
    private function hasNegativeFlagName(string $name): bool
    {
        // Delegate to the owning rule's predicate so the prefix set and camelCase/snake_case
        // boundary rules can never drift apart and drop a name between the two rules again.
        return NegativeBooleanRule::negativeFlagPrefix($name) !== null;
    }

    /**
     * Resolves the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope - Scope whose node yields the symbol name.
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
