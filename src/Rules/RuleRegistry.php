<?php

declare(strict_types=1);

namespace GruffPhp\Rules;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\ProjectRuleInterface;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use GruffPhp\Rules\Contracts\SourceTextRuleInterface;
use GruffPhp\Rules\Complexity\CognitiveComplexityRule;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Complexity\HalsteadVolumeRule;
use GruffPhp\Rules\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rules\Complexity\NestingDepthRule;
use GruffPhp\Rules\DeadCode\UnusedPrivateConstantRule;
use GruffPhp\Rules\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rules\DeadCode\UnusedPrivatePropertyRule;
use GruffPhp\Rules\Docs\MissingClassPhpdocRule;
use GruffPhp\Rules\Docs\MissingConstantPhpdocRule;
use GruffPhp\Rules\Docs\MissingFilePhpdocRule;
use GruffPhp\Rules\Docs\MissingParamTagRule;
use GruffPhp\Rules\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rules\Docs\MissingPublicPhpdocRule;
use GruffPhp\Rules\Docs\MissingReadmeRule;
use GruffPhp\Rules\Docs\MissingReturnTagRule;
use GruffPhp\Rules\Docs\MissingThrowsTagRule;
use GruffPhp\Rules\Docs\RegexCommentRule;
use GruffPhp\Rules\Docs\ReturnCommentRule;
use GruffPhp\Rules\Docs\StaleParamTagRule;
use GruffPhp\Rules\Docs\TodoDensityRule;
use GruffPhp\Rules\Docs\BarePhpdocTagsRule;
use GruffPhp\Rules\Docs\VarAnnotationDescriptionRule;
use GruffPhp\Rules\Modernisation\ConstructorPromotionCandidateRule;
use GruffPhp\Rules\Modernisation\FirstClassCallableCandidateRule;
use GruffPhp\Rules\Modernisation\ForbiddenGlobalAccessRule;
use GruffPhp\Rules\Modernisation\MatchExpressionCandidateRule;
use GruffPhp\Rules\Modernisation\MixedTypeOveruseRule;
use GruffPhp\Rules\Modernisation\NamedArgumentOpportunityRule;
use GruffPhp\Rules\Modernisation\PhpDocMixedOveruseRule;
use GruffPhp\Rules\Modernisation\PublicPropertyRule;
use GruffPhp\Rules\Modernisation\ReadonlyPropertyCandidateRule;
use GruffPhp\Rules\Naming\AbbreviationAllowlistRule;
use GruffPhp\Rules\Naming\BooleanPrefixRule;
use GruffPhp\Rules\Naming\ClassFileMismatchRule;
use GruffPhp\Rules\Naming\ConfusingNameRule;
use GruffPhp\Rules\Naming\GenericMethodNameRule;
use GruffPhp\Rules\Naming\HungarianNotationRule;
use GruffPhp\Rules\Naming\IdentifierQualityRule;
use GruffPhp\Rules\Naming\NegativeBooleanRule;
use GruffPhp\Rules\Naming\ShortVariableRule;
use GruffPhp\Rules\Naming\SuffixHungarianRule;
use GruffPhp\Rules\Naming\TestNamingConsistencyRule;
use GruffPhp\Rules\Security\DangerousFunctionCallRule;
use GruffPhp\Rules\Security\DebugModeEnabledRule;
use GruffPhp\Rules\Security\DependencyComposerPathRule;
use GruffPhp\Rules\Security\DependencyComposerScriptRule;
use GruffPhp\Rules\Security\DependencyComposerUnpinnedRule;
use GruffPhp\Rules\Security\DependencyComposerVcsRule;
use GruffPhp\Rules\Security\DisabledSslVerificationRule;
use GruffPhp\Rules\Security\ErrorSuppressionRule;
use GruffPhp\Rules\Security\ExtractCompactUserInputRule;
use GruffPhp\Rules\Security\GithubActionsRiskyWorkflowRule;
use GruffPhp\Rules\Security\HeaderInjectionRule;
use GruffPhp\Rules\Security\InsecureRandomRule;
use GruffPhp\Rules\Security\PathTraversalFileAccessRule;
use GruffPhp\Rules\Security\PermissiveCorsRule;
use GruffPhp\Rules\Security\ProcessCommandConstructionRule;
use GruffPhp\Rules\Security\ReflectedXssRule;
use GruffPhp\Rules\Security\RequestControlledUrlRule;
use GruffPhp\Rules\Security\SensitiveDataLoggingRule;
use GruffPhp\Rules\Security\SilentCatchRule;
use GruffPhp\Rules\Security\SqlConcatenationRule;
use GruffPhp\Rules\Security\UnsafeArchiveExtractionRule;
use GruffPhp\Rules\Security\UnsafeXmlLoadingRule;
use GruffPhp\Rules\Security\UnsafeUnserializeRule;
use GruffPhp\Rules\Security\VariableIncludeRule;
use GruffPhp\Rules\Security\WeakCryptoRule;
use GruffPhp\Rules\SensitiveData\ApiKeyPatternRule;
use GruffPhp\Rules\SensitiveData\AwsAccessKeyRule;
use GruffPhp\Rules\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rules\SensitiveData\GcpServiceAccountKeyRule;
use GruffPhp\Rules\SensitiveData\HardcodedEnvValueRule;
use GruffPhp\Rules\SensitiveData\HighEntropyStringRule;
use GruffPhp\Rules\SensitiveData\JwtTokenRule;
use GruffPhp\Rules\SensitiveData\PhiPatternRule;
use GruffPhp\Rules\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rules\SensitiveData\PrivateKeyRule;
use GruffPhp\Rules\SensitiveData\UrlEmbeddedCredentialsRule;
use GruffPhp\Rules\Shared\ProjectRuleAccumulator;
use GruffPhp\Rules\Shared\ProjectSourceTextRuleAccumulator;
use GruffPhp\Rules\Shared\RuleRunnerObserver;
use GruffPhp\Rules\Size\AverageMethodLengthRule;
use GruffPhp\Rules\Size\ClassLengthRule;
use GruffPhp\Rules\Size\FileLengthRule;
use GruffPhp\Rules\Size\MethodLengthRule;
use GruffPhp\Rules\Size\ParameterCountRule;
use GruffPhp\Rules\Size\PropertyCountRule;
use GruffPhp\Rules\Size\PublicMethodCountRule;
use GruffPhp\Rules\TestQuality\ConditionalTestLogicRule;
use GruffPhp\Rules\TestQuality\DataProviderAnnotationRule;
use GruffPhp\Rules\TestQuality\EagerTestRule;
use GruffPhp\Rules\TestQuality\EmptyDataProviderRule;
use GruffPhp\Rules\TestQuality\ExceptionTypeOnlyRule;
use GruffPhp\Rules\TestQuality\ExcessiveMockingRule;
use GruffPhp\Rules\TestQuality\ExtendsProductionClassRule;
use GruffPhp\Rules\TestQuality\GlobalStateMutationRule;
use GruffPhp\Rules\TestQuality\LoopAssertionWithoutMessageRule;
use GruffPhp\Rules\TestQuality\MagicNumberAssertionRule;
use GruffPhp\Rules\TestQuality\MockingDomainObjectRule;
use GruffPhp\Rules\TestQuality\MockOnlyTestRule;
use GruffPhp\Rules\TestQuality\MockWithoutExpectationRule;
use GruffPhp\Rules\TestQuality\MultipleAaaCyclesRule;
use GruffPhp\Rules\TestQuality\MysteryGuestRule;
use GruffPhp\Rules\TestQuality\NoAssertionsRule;
use GruffPhp\Rules\TestQuality\PhpUnitCoverageSourceMissingRule;
use GruffPhp\Rules\TestQuality\PhpUnitDeprecationsNotFatalRule;
use GruffPhp\Rules\TestQuality\PhpUnitStrictFlagsMissingRule;
use GruffPhp\Rules\TestQuality\PrivateReflectionRule;
use GruffPhp\Rules\TestQuality\RepeatedStructureMissingDataProviderRule;
use GruffPhp\Rules\TestQuality\SetupBloatRule;
use GruffPhp\Rules\TestQuality\SkippedWithoutReasonRule;
use GruffPhp\Rules\TestQuality\SleepInTestRule;
use GruffPhp\Rules\TestQuality\StaticAnalysisRedundantTestRule;
use GruffPhp\Rules\TestQuality\SutNotCalledRule;
use GruffPhp\Rules\TestQuality\TautologicalTypeAssertionRule;
use GruffPhp\Rules\TestQuality\TestdoxReadabilityRule;
use GruffPhp\Rules\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rules\TestQuality\TestMethodTooLongRule;
use GruffPhp\Rules\TestQuality\TestNamingConsistencyRule as TestQualityNamingConsistencyRule;
use GruffPhp\Rules\TestQuality\TrivialAssertionRule;
use GruffPhp\Rules\TestQuality\TrivialSnapshotRule;
use GruffPhp\Rules\TestQuality\UnusedMockRule;
use GruffPhp\Rules\Waste\CommentedOutCodeRule;
use GruffPhp\Rules\Waste\EmptyClassRule;
use GruffPhp\Rules\Waste\EmptyMethodRule;
use GruffPhp\Rules\Waste\OneLineMethodRule;
use GruffPhp\Rules\Waste\RedundantVariableRule;
use GruffPhp\Rules\Waste\UnreachableCodeRule;
use GruffPhp\Rules\Waste\UnusedImportRule;
use GruffPhp\Rules\Waste\UnusedParameterRule;
use InvalidArgumentException;
use WeakMap;

/**
 * Stores available rules and dispatches enabled rule analysis.
 */
final class RuleRegistry
{
    /**
     * Rule priority for overlapping naming findings on the same identifier.
     *
     * Lower numbers win. The order is the M51 documented deferral contract.
     */
    private const NAMING_RULE_PRIORITY = [
        'naming.class-file-mismatch'    => 0,
        'naming.confusing-name'         => 1,
        'naming.negative-boolean'       => 2,
        'naming.boolean-prefix'         => 3,
        'naming.identifier-quality'     => 4,
        'naming.hungarian-notation'     => 5,
        'naming.suffix-hungarian'       => 6,
        'naming.short-variable'         => 7,
        'naming.abbreviation-allowlist' => 8,
    ];

    /** @var array<string, RuleInterface|ProjectRuleInterface> */
    private readonly array $rules;

    /**
     * Rule definitions captured once at construction, keyed by rule id in registry order.
     *
     * Calling definition() rebuilds a RuleDefinition (with its preg-validating
     * constructor) on every invocation, so the enabled-rule filter reads from
     * this snapshot instead of re-asking each rule per call.
     *
     * @var array<string, RuleDefinition>
     */
    private readonly array $definitions;

    /**
     * Enabled-rule lists memoised per AnalysisConfig instance.
     *
     * AnalysisConfig is immutable (every with*() returns a new instance), so
     * object identity is a sound cache key and WeakMap keeps entries from
     * outliving their config.
     *
     * @var WeakMap<AnalysisConfig, list<RuleInterface|ProjectRuleInterface>>
     */
    private readonly WeakMap $enabledRulesByConfig;

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<RuleInterface|ProjectRuleInterface> $rules - Rule instances to index by id.
     *
     * @throws InvalidArgumentException When two rules declare the same id.
     */
    public function __construct(array $rules)
    {
        $indexedRules       = [];
        $indexedDefinitions = [];

        // User view: add each item that can appear in findings list.
        foreach ($rules as $rule) {
            $definition = $rule->definition();
            $id         = $definition->id;

            // User view: choose the findings list branch for this case.
            if (isset($indexedRules[$id])) {
                throw new InvalidArgumentException(sprintf('Duplicate rule id "%s".', $id));
            }

            $indexedRules[$id]       = $rule;
            $indexedDefinitions[$id] = $definition;
        }

        ksort($indexedRules, SORT_STRING);
        ksort($indexedDefinitions, SORT_STRING);
        $this->rules                = $indexedRules;
        $this->definitions          = $indexedDefinitions;
        $this->enabledRulesByConfig = new WeakMap();
    }

    /**
     * Build the default registry containing every built-in rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return self - registry pre-loaded with every built-in rule, keyed and sorted by rule id
     */
    public static function defaults(): self
    {
        return new self([
                            new CognitiveComplexityRule(),
                            new CyclomaticComplexityRule(),
                            new HalsteadVolumeRule(),
                            new MaintainabilityIndexRule(),
                            new NestingDepthRule(),
                            new UnusedPrivateConstantRule(),
                            new UnusedPrivateMethodRule(),
                            new UnusedPrivatePropertyRule(),
                            new CommentedOutCodeRule(),
                            new EmptyClassRule(),
                            new EmptyMethodRule(),
                            new OneLineMethodRule(),
                            new RedundantVariableRule(),
                            new UnreachableCodeRule(),
                            new UnusedImportRule(),
                            new UnusedParameterRule(),
                            new AbbreviationAllowlistRule(),
                            new BooleanPrefixRule(),
                            new ClassFileMismatchRule(),
                            new ConfusingNameRule(),
                            new GenericMethodNameRule(),
                            new HungarianNotationRule(),
                            new IdentifierQualityRule(),
                            new NegativeBooleanRule(),
                            new ShortVariableRule(),
                            new SuffixHungarianRule(),
                            new TestNamingConsistencyRule(),
                            new ConstructorPromotionCandidateRule(),
                            new FirstClassCallableCandidateRule(),
                            new ForbiddenGlobalAccessRule(),
                            new MatchExpressionCandidateRule(),
                            new MixedTypeOveruseRule(),
                            new NamedArgumentOpportunityRule(),
                            new PhpDocMixedOveruseRule(),
                            new PublicPropertyRule(),
                            new ReadonlyPropertyCandidateRule(),
                            new ApiKeyPatternRule(),
                            new AwsAccessKeyRule(),
                            new DatabaseUrlPasswordRule(),
                            new GcpServiceAccountKeyRule(),
                            new HardcodedEnvValueRule(),
                            new HighEntropyStringRule(),
                            new JwtTokenRule(),
                            new PhiPatternRule(),
                            new PiiTestFixtureRule(),
                            new PrivateKeyRule(),
                            new UrlEmbeddedCredentialsRule(),
                            new DangerousFunctionCallRule(),
                            new DebugModeEnabledRule(),
                            new DependencyComposerPathRule(),
                            new DependencyComposerScriptRule(),
                            new DependencyComposerUnpinnedRule(),
                            new DependencyComposerVcsRule(),
                            new DisabledSslVerificationRule(),
                            new ErrorSuppressionRule(),
                            new ExtractCompactUserInputRule(),
                            new GithubActionsRiskyWorkflowRule(),
                            new HeaderInjectionRule(),
                            new InsecureRandomRule(),
                            new PathTraversalFileAccessRule(),
                            new PermissiveCorsRule(),
                            new ProcessCommandConstructionRule(),
                            new ReflectedXssRule(),
                            new RequestControlledUrlRule(),
                            new SensitiveDataLoggingRule(),
                            new SilentCatchRule(),
                            new SqlConcatenationRule(),
                            new UnsafeArchiveExtractionRule(),
                            new UnsafeXmlLoadingRule(),
                            new UnsafeUnserializeRule(),
                            new VariableIncludeRule(),
                            new WeakCryptoRule(),
                            new ConditionalTestLogicRule(),
                            new DataProviderAnnotationRule(),
                            new EagerTestRule(),
                            new EmptyDataProviderRule(),
                            new ExceptionTypeOnlyRule(),
                            new ExcessiveMockingRule(),
                            new ExtendsProductionClassRule(),
                            new GlobalStateMutationRule(),
                            new LoopAssertionWithoutMessageRule(),
                            new MagicNumberAssertionRule(),
                            new MockOnlyTestRule(),
                            new MockWithoutExpectationRule(),
                            new MockingDomainObjectRule(),
                            new MultipleAaaCyclesRule(),
                            new MysteryGuestRule(),
                            new NoAssertionsRule(),
                            new PhpUnitCoverageSourceMissingRule(),
                            new PhpUnitDeprecationsNotFatalRule(),
                            new PhpUnitStrictFlagsMissingRule(),
                            new PrivateReflectionRule(),
                            new RepeatedStructureMissingDataProviderRule(),
                            new SetupBloatRule(),
                            new SkippedWithoutReasonRule(),
                            new SleepInTestRule(),
                            new StaticAnalysisRedundantTestRule(),
                            new SutNotCalledRule(),
                            new TautologicalTypeAssertionRule(),
                            new TestLongerThanSutRule(),
                            new TestMethodTooLongRule(),
                            new TestQualityNamingConsistencyRule(),
                            new TestdoxReadabilityRule(),
                            new TrivialAssertionRule(),
                            new TrivialSnapshotRule(),
                            new UnusedMockRule(),
                            new MissingClassPhpdocRule(),
                            new MissingConstantPhpdocRule(),
                            new MissingFilePhpdocRule(),
                            new MissingParamTagRule(),
                            new MissingPropertyPhpdocRule(),
                            new MissingPublicPhpdocRule(),
                            new MissingReadmeRule(),
                            new MissingReturnTagRule(),
                            new MissingThrowsTagRule(),
                            new RegexCommentRule(),
                            new ReturnCommentRule(),
                            new StaleParamTagRule(),
                            new TodoDensityRule(),
                            new BarePhpdocTagsRule(),
                            new VarAnnotationDescriptionRule(),
                            new AverageMethodLengthRule(),
                            new ClassLengthRule(),
                            new FileLengthRule(),
                            new MethodLengthRule(),
                            new ParameterCountRule(),
                            new PropertyCountRule(),
                            new PublicMethodCountRule(),
                        ]);
    }

    /**
     * List every registered rule in execution order.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return list<RuleInterface|ProjectRuleInterface> - all registered rules, id-sorted ascending; empty when none were registered
     */
    public function all(): array
    {
        return array_values($this->rules);
    }

    /**
     * Check whether a rule id is registered.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $ruleId - Rule identifier to check.
     *
     * @return bool - true when a rule with this id is registered; false for unknown or misspelled ids
     */
    public function has(string $ruleId): bool
    {
        return isset($this->rules[$ruleId]);
    }

    /**
     * Return the requested rule ids that are not registered.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<string> $ruleIds - Rule identifiers to validate.
     *
     * @return list<string> - Unknown ids in first-seen order, de-duplicated.
     */
    public function unknownRuleIds(array $ruleIds): array
    {
        $unknown = [];
        // User view: add each item that can appear in findings list.
        foreach ($ruleIds as $ruleId) {
            // User view: choose the findings list branch for this case.
            if (isset($unknown[$ruleId]) || $this->has($ruleId)) {
                continue;
            }

            $unknown[$ruleId] = true;
        }

        return array_keys($unknown);
    }

    /**
     * Return a registered rule by id.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $ruleId - Rule identifier to look up.
     *
     * @return RuleInterface|ProjectRuleInterface - the shared rule instance registered under this id; never null (throws on miss)
     * @throws InvalidArgumentException When the rule id is unknown.
     */
    public function get(string $ruleId): RuleInterface|ProjectRuleInterface
    {
        return $this->rules[$ruleId]
               // User view: missing data becomes a safe findings list default.
               ?? throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
    }

    /**
     * Return rules enabled by the effective analysis config.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisConfig $config - Config used to filter registered rules.
     *
     * @return list<RuleInterface|ProjectRuleInterface> - rules passing both per-rule toggle and selection filter, id-sorted; empty when config
     *                                                  disables all
     */
    public function enabledRules(AnalysisConfig $config): array
    {
        return $this->enabledRulesByConfig[$config] ??= $this->filterEnabledRules($config);
    }

    /**
     * Filter the registered rules down to the set the config enables.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisConfig $config - Config used to filter registered rules.
     *
     * @return list<RuleInterface|ProjectRuleInterface> - rules passing both per-rule toggle and selection filter, id-sorted; empty when config
     *                                                  disables all
     */
    private function filterEnabledRules(AnalysisConfig $config): array
    {
        $selection    = $config->ruleSelection();
        $enabledRules = [];

        // User view: add each item that can appear in findings list.
        foreach ($this->definitions as $ruleId => $definition) {
            // User view: choose the findings list branch for this case.
            if ($config->ruleSettings($ruleId)->enabled && $selection->allows($definition)) {
                $enabledRules[] = $this->rules[$ruleId];
            }
        }

        return $enabledRules;
    }

    /**
     * Check whether the effective config enables at least one project-level rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisConfig $config - Config used to filter registered rules.
     *
     * @return bool - true when at least one enabled rule needs whole-project context; false when a per-unit pass suffices
     */
    public function hasEnabledProjectRules(AnalysisConfig $config): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ($this->enabledRules($config) as $rule) {
            // User view: choose the findings list branch for this case.
            if ($rule instanceof ProjectRuleInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return enabled rule ids whose findings come from project-wide analysis.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisConfig $config - Config used to filter registered rules.
     *
     * @return list<string> - Enabled ProjectRuleInterface ids in registry order.
     */
    public function enabledProjectRuleIds(AnalysisConfig $config): array
    {
        $ruleIds = [];

        // User view: add each item that can appear in findings list.
        foreach ($this->enabledRules($config) as $rule) {
            // User view: choose the findings list branch for this case.
            if ($rule instanceof ProjectRuleInterface) {
                $ruleIds[] = $rule->definition()->id;
            }
        }

        return $ruleIds;
    }

    /**
     * Determine whether the enabled rule set is fully streaming-capable.
     *
     * A rule set is streaming-capable when every enabled project rule
     * implements ProjectRuleAccumulator. Per-unit rules are always
     * streaming-friendly.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param RuleContext $ruleContext - Rule execution context.
     *
     * @return bool - true when the run can stream unit-by-unit; false when a legacy project rule forces buffering all units
     */
    public function supportsStreaming(RuleContext $ruleContext): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            // User view: choose the findings list branch for this case.
            if ($rule instanceof ProjectRuleInterface && !$rule instanceof ProjectRuleAccumulator) {
                return false;
            }
        }

        return true;
    }

    /**
     * Initialise project-rule accumulators before a streaming analysis pass.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param RuleContext $ruleContext - Rule execution context.
     *
     * @return void
     */
    public function beginStreaming(RuleContext $ruleContext): void
    {
        // User view: add each item that can appear in findings list.
        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            // User view: choose the findings list branch for this case.
            if ($rule instanceof ProjectRuleAccumulator) {
                $rule->startProject($ruleContext);
            }
        }
    }

    /**
     * Run every enabled per-unit rule and accumulator against a single unit.
     *
     * Use together with beginStreaming() and endStreaming() to drive a
     * parse → analyse → release pipeline that keeps peak memory close to
     * one unit's worth on large codebases.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit            $analysisUnit - Parsed unit to analyse.
     * @param RuleContext             $ruleContext - Rule execution context.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Optional per-rule timing hook.
     *
     * @return list<Finding> - file-scoped findings for this unit only; accumulator output is deferred to endStreaming()
     */
    public function analyseUnit(
        AnalysisUnit        $analysisUnit,
        RuleContext         $ruleContext,
        ?RuleRunnerObserver $ruleRunnerObserver = null,
    ): array {
        $findings = $this->runPerUnitRules($analysisUnit, $ruleContext, $ruleRunnerObserver);
        $this->accumulateForUnit($analysisUnit, $ruleContext, $ruleRunnerObserver);

        return $findings;
    }

    /**
     * Run only the per-unit (file-scoped) rules against a single unit.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit            $analysisUnit - Parsed unit to analyse.
     * @param RuleContext             $ruleContext - Rule execution context.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Optional per-rule timing hook.
     *
     * @return list<Finding> - findings from per-unit rules in rule-execution order, not yet deduped or final-sorted
     */
    private function runPerUnitRules(
        AnalysisUnit        $analysisUnit,
        RuleContext         $ruleContext,
        ?RuleRunnerObserver $ruleRunnerObserver,
    ): array {
        // User view: choose the findings list branch for this case.
        if ($analysisUnit->hasParseErrors()) {
            return [];
        }

        $findings = [];
        $isPhp    = $analysisUnit->file->isPhp();

        // User view: add each item that can appear in findings list.
        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            // User view: choose the findings list branch for this case.
            if (!$rule instanceof RuleInterface) {
                continue;
            }
            // User view: choose the findings list branch for this case.
            if (!$isPhp && !$rule instanceof SourceTextRuleInterface) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($ruleRunnerObserver === null) {
                array_push($findings, ...$rule->analyse($analysisUnit, $ruleContext));
                continue;
            }

            $ruleId       = $rule->definition()->id;
            $started      = hrtime(true);
            $ruleFindings = $rule->analyse($analysisUnit, $ruleContext);
            $ruleRunnerObserver->onRuleExecuted($ruleId, hrtime(true) - $started);
            array_push($findings, ...$ruleFindings);
        }

        return $findings;
    }

    /**
     * Push one unit through every enabled streaming project rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit            $analysisUnit - Parsed unit to accumulate.
     * @param RuleContext             $ruleContext - Rule execution context.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Optional per-rule timing hook.
     *
     * @return void
     */
    private function accumulateForUnit(
        AnalysisUnit        $analysisUnit,
        RuleContext         $ruleContext,
        ?RuleRunnerObserver $ruleRunnerObserver,
    ): void {
        // User view: choose the findings list branch for this case.
        if ($analysisUnit->hasParseErrors()) {
            return;
        }

        $isPhp = $analysisUnit->file->isPhp();
        // User view: add each item that can appear in findings list.
        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            // User view: choose the findings list branch for this case.
            if (!$rule instanceof ProjectRuleAccumulator) {
                continue;
            }
            // User view: choose the findings list branch for this case.
            if (!$isPhp && !$rule instanceof ProjectSourceTextRuleAccumulator) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($ruleRunnerObserver === null) {
                $rule->accumulate($analysisUnit, $ruleContext);
                continue;
            }

            $ruleId  = $rule->definition()->id;
            $started = hrtime(true);
            $rule->accumulate($analysisUnit, $ruleContext);
            $ruleRunnerObserver->onRuleExecuted($ruleId, hrtime(true) - $started);
        }
    }

    /**
     * Finalise project-rule accumulators after streaming analysis completes.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param RuleContext             $ruleContext - Rule execution context.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Optional per-rule timing hook.
     *
     * @return list<Finding> - project-level findings flushed from accumulator state; empty when no accumulators ran or matched
     */
    public function endStreaming(
        RuleContext         $ruleContext,
        ?RuleRunnerObserver $ruleRunnerObserver = null,
    ): array {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            // User view: choose the findings list branch for this case.
            if (!$rule instanceof ProjectRuleAccumulator) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($ruleRunnerObserver === null) {
                array_push($findings, ...$rule->finishProject($ruleContext));
                continue;
            }

            $ruleId          = $rule->definition()->id;
            $started         = hrtime(true);
            $projectFindings = $rule->finishProject($ruleContext);
            $ruleRunnerObserver->onRuleExecuted($ruleId, hrtime(true) - $started);
            array_push($findings, ...$projectFindings);
        }

        return $findings;
    }

    /**
     * Apply the canonical ordering and dedupe pass to a streaming run's
     * collected findings. Callers that drive analyseUnit() / endStreaming()
     * themselves should call this once at the end so the output matches
     * the non-streaming analyse() flow byte-for-byte.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<Finding> $findings - Raw findings collected by the streaming analysis path.
     *
     * @return list<Finding> - deduped findings in canonical report order (file, line, rule id, message); empty when no findings survive
     */
    public function finalizeFindings(array $findings): array
    {
        $findings = $this->deduplicateNamingFindings($this->deduplicateFindings($findings));

        usort(
            $findings,
            static fn(Finding $leftFinding, Finding $rightFinding): int => [
                                                                               $leftFinding->filePath,
                                                                               // User view: missing data becomes a safe findings list default.
                                                                               $leftFinding->line ?? 0,
                                                                               $leftFinding->ruleId,
                                                                               $leftFinding->message,
                                                                           ] <=> [
                                                                               $rightFinding->filePath,
                                                                               // User view: missing data becomes a safe findings list default.
                                                                               $rightFinding->line ?? 0,
                                                                               $rightFinding->ruleId,
                                                                               $rightFinding->message,
                                                                           ],
        );

        return $findings;
    }

    /**
     * Run all enabled file and project rules against parsed units.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<AnalysisUnit>      $units - Parsed units to analyse with file-scoped rules.
     * @param RuleContext             $ruleContext - Rule execution context.
     * @param list<AnalysisUnit>|null $projectUnits - Parsed units available to project-level rules.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Optional per-rule timing hook; default analyse runs leave this null.
     * @param bool                    $shouldReleaseUnitsAfterAnalysis - Whether units can release AST contents after analysis.
     *
     * @return list<Finding> - all per-unit, accumulator, and legacy project findings, deduped and in canonical report order
     */
    public function analyse(
        array               $units,
        RuleContext         $ruleContext,
        ?array              $projectUnits = null,
        ?RuleRunnerObserver $ruleRunnerObserver = null,
        bool                $shouldReleaseUnitsAfterAnalysis = false,
    ): array {
        $legacyProjectRules = $this->legacyProjectRules($ruleContext);
        $canReleaseUnits    = $shouldReleaseUnitsAfterAnalysis
                              // User view: an empty value becomes a clear findings list fallback.
                              && $legacyProjectRules === []
                              // User view: missing data becomes the expected findings list state.
                              && $projectUnits === null;

        $this->beginStreaming($ruleContext);

        $findings = [];
        // User view: add each item that can appear in findings list.
        foreach ($units as $unit) {
            array_push($findings, ...$this->runPerUnitRules($unit, $ruleContext, $ruleRunnerObserver));
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($projectUnits === null) {
                $this->accumulateForUnit($unit, $ruleContext, $ruleRunnerObserver);
            }
            // User view: choose the findings list branch for this case.
            if ($canReleaseUnits) {
                $unit->release();
            }
        }

        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($projectUnits !== null) {
            // User view: add each item that can appear in findings list.
            foreach ($projectUnits as $contextUnit) {
                $this->accumulateForUnit($contextUnit, $ruleContext, $ruleRunnerObserver);
            }
        }

        array_push($findings, ...$this->endStreaming($ruleContext, $ruleRunnerObserver));

        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($legacyProjectRules !== []) {
            array_push($findings, ...$this->runLegacyProjectRules(
                $legacyProjectRules,
                // User view: missing data becomes a safe findings list default.
                $projectUnits ?? $units,
                $ruleContext,
                $ruleRunnerObserver,
            ));
        }

        return $this->finalizeFindings($findings);
    }

    /**
     * Find enabled project rules that still need the full unit list.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param RuleContext $ruleContext - Rule execution context.
     *
     * @return list<ProjectRuleInterface> - enabled project rules lacking accumulator support, which must run with the full unit list; empty when all
     *                                    stream
     */
    private function legacyProjectRules(RuleContext $ruleContext): array
    {
        $rules = [];
        // User view: add each item that can appear in findings list.
        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            // User view: choose the findings list branch for this case.
            if ($rule instanceof ProjectRuleInterface && !$rule instanceof ProjectRuleAccumulator) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Run project-level rules that need the full analysis context.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<ProjectRuleInterface> $rules - Project rules to run.
     * @param list<AnalysisUnit>         $contextUnits - Candidate units available to project rules.
     * @param RuleContext                $ruleContext - Rule execution context.
     * @param RuleRunnerObserver|null    $ruleRunnerObserver - Optional per-rule timing hook.
     *
     * @return list<Finding> - findings from the supplied legacy project rules; empty when no parse-clean PHP units remain to analyse
     */
    private function runLegacyProjectRules(
        array               $rules,
        array               $contextUnits,
        RuleContext         $ruleContext,
        ?RuleRunnerObserver $ruleRunnerObserver,
    ): array {
        $analyseableUnits = array_values(array_filter(
                                             $contextUnits,
                                             static fn(AnalysisUnit $analysisUnit): bool => !$analysisUnit->hasParseErrors() && $analysisUnit->file->isPhp(),
                                         ));

        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($analyseableUnits === []) {
            return [];
        }

        $findings = [];
        // User view: add each item that can appear in findings list.
        foreach ($rules as $rule) {
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($ruleRunnerObserver === null) {
                array_push($findings, ...$rule->analyseProject($analyseableUnits, $ruleContext));
                continue;
            }

            $ruleId          = $rule->definition()->id;
            $started         = hrtime(true);
            $projectFindings = $rule->analyseProject($analyseableUnits, $ruleContext);
            $ruleRunnerObserver->onRuleExecuted($ruleId, hrtime(true) - $started);
            array_push($findings, ...$projectFindings);
        }

        return $findings;
    }

    /**
     * Build deduplicate findings for the component.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<Finding> $findings - Findings to collapse by full reporting identity.
     *
     * @return list<Finding> - input order preserved with later exact-identity duplicates dropped (first occurrence wins)
     */
    private function deduplicateFindings(array $findings): array
    {
        $seen           = [];
        $uniqueFindings = [];

        // User view: add each item that can appear in findings list.
        foreach ($findings as $finding) {
            $key = implode("\0", [
                $finding->ruleId,
                $finding->filePath,
                // User view: missing data becomes a safe findings list default.
                (string)($finding->line ?? ''),
                // User view: missing data becomes a safe findings list default.
                (string)($finding->endLine ?? ''),
                // User view: missing data becomes a safe findings list default.
                (string)($finding->column ?? ''),
                // User view: missing data becomes a safe findings list default.
                $finding->symbol ?? '',
                $finding->message,
                // User view: an empty value becomes a clear findings list fallback.
                $finding->metadata === [] ? '' : serialize($finding->metadata),
            ]);

            // User view: choose the findings list branch for this case.
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key]       = true;
            $uniqueFindings[] = $finding;
        }

        return $uniqueFindings;
    }

    /**
     * Keep only the highest-priority naming finding when multiple naming rules
     * report the same identifier at the same source location.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<Finding> $findings - Findings that may contain overlapping naming reports.
     *
     * @return list<Finding> - relative order preserved, keeping only the highest-priority naming finding per overlapping identifier
     */
    private function deduplicateNamingFindings(array $findings): array
    {
        $bestByIdentifier = [];
        $selectedIndexes  = [];

        // User view: add each item that can appear in findings list.
        foreach ($findings as $index => $finding) {
            $key = $this->namingOverlapKey($finding);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($key === null) {
                $selectedIndexes[$index] = true;
                continue;
            }

            $priority = self::NAMING_RULE_PRIORITY[$finding->ruleId];
            // User view: choose the findings list branch for this case.
            if (!isset($bestByIdentifier[$key]) || $priority < $bestByIdentifier[$key]['priority']) {
                $bestByIdentifier[$key] = ['index' => $index, 'priority' => $priority];
            }
        }

        // User view: add each item that can appear in findings list.
        foreach ($bestByIdentifier as $selected) {
            $selectedIndexes[$selected['index']] = true;
        }

        ksort($selectedIndexes, SORT_NUMERIC);

        return array_values(array_intersect_key($findings, $selectedIndexes));
    }

    /**
     * Build the cross-rule identifier key used to collapse duplicate naming findings.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Finding $finding - Finding to classify for naming-rule overlap.
     *
     * @return string|null - overlap-bucket key (file, line, column, symbol, identifier); null when the finding cannot participate in naming dedup
     */
    private function namingOverlapKey(Finding $finding): ?string
    {
        // User view: choose the findings list branch for this case.
        if (!isset(self::NAMING_RULE_PRIORITY[$finding->ruleId])) {
            return null;
        }

        $identifierName = $this->findingIdentifierName($finding);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($identifierName === null) {
            return null;
        }

        return implode("\0", [
            $finding->filePath,
            // User view: missing data becomes a safe findings list default.
            (string)($finding->line ?? ''),
            // User view: missing data becomes a safe findings list default.
            (string)($finding->column ?? ''),
            // User view: missing data becomes a safe findings list default.
            $finding->symbol ?? '',
            strtolower($identifierName),
        ]);
    }

    /**
     * Extract the identifier name from finding metadata.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Finding $finding - Finding whose metadata may carry an identifier.
     *
     * @return string|null - identifier from metadata, falling back to the finding symbol; null when neither is present
     */
    private function findingIdentifierName(Finding $finding): ?string
    {
        // User view: add each item that can appear in findings list.
        foreach (['identifierName', 'variable', 'parameter'] as $metadataKey) {
            // User view: missing data becomes a safe findings list default.
            $metadataValue = $finding->metadata[$metadataKey] ?? null;
            // User view: choose the findings list branch for this case.
            if (is_string($metadataValue)) {
                return $metadataValue;
            }
        }

        return $finding->symbol;
    }
}
