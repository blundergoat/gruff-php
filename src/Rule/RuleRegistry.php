<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CognitiveComplexityRule;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\Complexity\HalsteadVolumeRule;
use GruffPhp\Rule\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rule\Complexity\NestingDepthRule;
use GruffPhp\Rule\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rule\DeadCode\UnusedPrivatePropertyRule;
use GruffPhp\Rule\Design\SingleImplementorInterfaceRule;
use GruffPhp\Rule\Docs\MissingClassPhpdocRule;
use GruffPhp\Rule\Docs\MissingConstantPhpdocRule;
use GruffPhp\Rule\Docs\MissingFilePhpdocRule;
use GruffPhp\Rule\Docs\MissingParamTagRule;
use GruffPhp\Rule\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rule\Docs\MissingPublicPhpdocRule;
use GruffPhp\Rule\Docs\MissingReadmeRule;
use GruffPhp\Rule\Docs\MissingReturnTagRule;
use GruffPhp\Rule\Docs\MissingThrowsTagRule;
use GruffPhp\Rule\Docs\RegexCommentRule;
use GruffPhp\Rule\Docs\ReturnCommentRule;
use GruffPhp\Rule\Docs\StaleParamTagRule;
use GruffPhp\Rule\Docs\TodoDensityRule;
use GruffPhp\Rule\Docs\BarePhpdocTagsRule;
use GruffPhp\Rule\Docs\VarAnnotationDescriptionRule;
use GruffPhp\Rule\Modernisation\ConstructorPromotionCandidateRule;
use GruffPhp\Rule\Modernisation\EnumCandidateRule;
use GruffPhp\Rule\Modernisation\FirstClassCallableCandidateRule;
use GruffPhp\Rule\Modernisation\ForbiddenGlobalAccessRule;
use GruffPhp\Rule\Modernisation\MatchExpressionCandidateRule;
use GruffPhp\Rule\Modernisation\MixedTypeOveruseRule;
use GruffPhp\Rule\Modernisation\NamedArgumentOpportunityRule;
use GruffPhp\Rule\Modernisation\PhpDocMixedOveruseRule;
use GruffPhp\Rule\Modernisation\PublicPropertyRule;
use GruffPhp\Rule\Modernisation\ReadonlyPropertyCandidateRule;
use GruffPhp\Rule\Naming\AbbreviationAllowlistRule;
use GruffPhp\Rule\Naming\BooleanPrefixRule;
use GruffPhp\Rule\Naming\ClassFileMismatchRule;
use GruffPhp\Rule\Naming\ConfusingNameRule;
use GruffPhp\Rule\Naming\GenericMethodNameRule;
use GruffPhp\Rule\Naming\HungarianNotationRule;
use GruffPhp\Rule\Naming\IdentifierQualityRule;
use GruffPhp\Rule\Naming\NegativeBooleanRule;
use GruffPhp\Rule\Naming\ShortVariableRule;
use GruffPhp\Rule\Naming\SuffixHungarianRule;
use GruffPhp\Rule\Naming\TestNamingConsistencyRule;
use GruffPhp\Rule\Security\DangerousFunctionCallRule;
use GruffPhp\Rule\Security\DebugModeEnabledRule;
use GruffPhp\Rule\Security\DependencyComposerPathRule;
use GruffPhp\Rule\Security\DependencyComposerScriptRule;
use GruffPhp\Rule\Security\DependencyComposerUnpinnedRule;
use GruffPhp\Rule\Security\DependencyComposerVcsRule;
use GruffPhp\Rule\Security\DisabledSslVerificationRule;
use GruffPhp\Rule\Security\ErrorSuppressionRule;
use GruffPhp\Rule\Security\ExtractCompactUserInputRule;
use GruffPhp\Rule\Security\GithubActionsRiskyWorkflowRule;
use GruffPhp\Rule\Security\HeaderInjectionRule;
use GruffPhp\Rule\Security\InsecureRandomRule;
use GruffPhp\Rule\Security\PathTraversalFileAccessRule;
use GruffPhp\Rule\Security\PermissiveCorsRule;
use GruffPhp\Rule\Security\ProcessCommandConstructionRule;
use GruffPhp\Rule\Security\ReflectedXssRule;
use GruffPhp\Rule\Security\RequestControlledUrlRule;
use GruffPhp\Rule\Security\SensitiveDataLoggingRule;
use GruffPhp\Rule\Security\SilentCatchRule;
use GruffPhp\Rule\Security\SqlConcatenationRule;
use GruffPhp\Rule\Security\UnsafeArchiveExtractionRule;
use GruffPhp\Rule\Security\UnsafeXmlLoadingRule;
use GruffPhp\Rule\Security\UnsafeUnserializeRule;
use GruffPhp\Rule\Security\VariableIncludeRule;
use GruffPhp\Rule\Security\WeakCryptoRule;
use GruffPhp\Rule\SensitiveData\ApiKeyPatternRule;
use GruffPhp\Rule\SensitiveData\AwsAccessKeyRule;
use GruffPhp\Rule\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rule\SensitiveData\GcpServiceAccountKeyRule;
use GruffPhp\Rule\SensitiveData\HardcodedEnvValueRule;
use GruffPhp\Rule\SensitiveData\HighEntropyStringRule;
use GruffPhp\Rule\SensitiveData\JwtTokenRule;
use GruffPhp\Rule\SensitiveData\PhiPatternRule;
use GruffPhp\Rule\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rule\SensitiveData\PrivateKeyRule;
use GruffPhp\Rule\SensitiveData\UrlEmbeddedCredentialsRule;
use GruffPhp\Rule\Size\AverageMethodLengthRule;
use GruffPhp\Rule\Size\ClassLengthRule;
use GruffPhp\Rule\Size\FileLengthRule;
use GruffPhp\Rule\Size\MethodLengthRule;
use GruffPhp\Rule\Size\ParameterCountRule;
use GruffPhp\Rule\Size\PropertyCountRule;
use GruffPhp\Rule\Size\PublicMethodCountRule;
use GruffPhp\Rule\TestQuality\ConditionalTestLogicRule;
use GruffPhp\Rule\TestQuality\DataProviderAnnotationRule;
use GruffPhp\Rule\TestQuality\EagerTestRule;
use GruffPhp\Rule\TestQuality\EmptyDataProviderRule;
use GruffPhp\Rule\TestQuality\ExceptionTypeOnlyRule;
use GruffPhp\Rule\TestQuality\ExcessiveMockingRule;
use GruffPhp\Rule\TestQuality\ExtendsProductionClassRule;
use GruffPhp\Rule\TestQuality\GlobalStateMutationRule;
use GruffPhp\Rule\TestQuality\LoopAssertionWithoutMessageRule;
use GruffPhp\Rule\TestQuality\MagicNumberAssertionRule;
use GruffPhp\Rule\TestQuality\MockingDomainObjectRule;
use GruffPhp\Rule\TestQuality\MockOnlyTestRule;
use GruffPhp\Rule\TestQuality\MockWithoutExpectationRule;
use GruffPhp\Rule\TestQuality\MultipleAaaCyclesRule;
use GruffPhp\Rule\TestQuality\MysteryGuestRule;
use GruffPhp\Rule\TestQuality\NoAssertionsRule;
use GruffPhp\Rule\TestQuality\PhpUnitCoverageSourceMissingRule;
use GruffPhp\Rule\TestQuality\PhpUnitDeprecationsNotFatalRule;
use GruffPhp\Rule\TestQuality\PhpUnitStrictFlagsMissingRule;
use GruffPhp\Rule\TestQuality\PrivateReflectionRule;
use GruffPhp\Rule\TestQuality\RepeatedStructureMissingDataProviderRule;
use GruffPhp\Rule\TestQuality\SetupBloatRule;
use GruffPhp\Rule\TestQuality\SkippedWithoutReasonRule;
use GruffPhp\Rule\TestQuality\SleepInTestRule;
use GruffPhp\Rule\TestQuality\SutNotCalledRule;
use GruffPhp\Rule\TestQuality\TautologicalTypeAssertionRule;
use GruffPhp\Rule\TestQuality\TestdoxReadabilityRule;
use GruffPhp\Rule\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rule\TestQuality\TestMethodTooLongRule;
use GruffPhp\Rule\TestQuality\TestNamingConsistencyRule as TestQualityNamingConsistencyRule;
use GruffPhp\Rule\TestQuality\TrivialAssertionRule;
use GruffPhp\Rule\TestQuality\TrivialSnapshotRule;
use GruffPhp\Rule\TestQuality\UnusedMockRule;
use GruffPhp\Rule\Waste\CommentedOutCodeRule;
use GruffPhp\Rule\Waste\EmptyClassRule;
use GruffPhp\Rule\Waste\EmptyMethodRule;
use GruffPhp\Rule\Waste\OneLineMethodRule;
use GruffPhp\Rule\Waste\RedundantVariableRule;
use GruffPhp\Rule\Waste\UnreachableCodeRule;
use GruffPhp\Rule\Waste\UnusedImportRule;
use GruffPhp\Rule\Waste\UnusedParameterRule;
use InvalidArgumentException;

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
     * @param list<RuleInterface|ProjectRuleInterface> $rules Rule instances to index by id.
     *
     * @throws InvalidArgumentException When two rules declare the same id.
     */
    public function __construct(array $rules)
    {
        $indexedRules = [];

        foreach ($rules as $rule) {
            $id = $rule->definition()->id;

            if (isset($indexedRules[$id])) {
                throw new InvalidArgumentException(sprintf('Duplicate rule id "%s".', $id));
            }

            $indexedRules[$id] = $rule;
        }

        ksort($indexedRules, SORT_STRING);
        $this->rules = $indexedRules;
    }

    /**
     * Build the default registry containing every built-in rule.
     *
     * @return self - registry pre-loaded with every built-in rule, keyed and sorted by rule id
     */
    public static function defaults(): self
    {
        // The built-in catalogue is centralised here so config, docs, and tests share one source.
        return new self([
                            new CognitiveComplexityRule(),
                            new CyclomaticComplexityRule(),
                            new HalsteadVolumeRule(),
                            new MaintainabilityIndexRule(),
                            new NestingDepthRule(),
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
                            new EnumCandidateRule(),
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
                            new SingleImplementorInterfaceRule(),
                        ]);
    }

    /**
     * List every registered rule in execution order.
     *
     * @return list<RuleInterface|ProjectRuleInterface> - all registered rules, id-sorted ascending; empty when none were registered
     */
    public function all(): array
    {
        // Callers consume rules as a list while the registry stores them by id.
        return array_values($this->rules);
    }

    /**
     * Check whether a rule id is registered.
     *
     * @param string $ruleId Rule identifier to check.
     *
     * @return bool - true when a rule with this id is registered; false for unknown or misspelled ids
     */
    public function has(string $ruleId): bool
    {
        // The constructor guarantees registered ids are unique array keys.
        return isset($this->rules[$ruleId]);
    }

    /**
     * Return a registered rule by id.
     *
     * @param string $ruleId Rule identifier to look up.
     *
     * @return RuleInterface|ProjectRuleInterface - the shared rule instance registered under this id; never null (throws on miss)
     * @throws InvalidArgumentException When the rule id is unknown.
     */
    public function get(string $ruleId): RuleInterface|ProjectRuleInterface
    {
        // Unknown ids are caller/config mistakes, so surface them immediately.
        return $this->rules[$ruleId]
               ?? throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
    }

    /**
     * Return rules enabled by the effective analysis config.
     *
     * @param AnalysisConfig $config Config used to filter registered rules.
     *
     * @return list<RuleInterface|ProjectRuleInterface> - rules passing both per-rule toggle and selection filter, id-sorted; empty when config
     *                                                  disables all
     */
    public function enabledRules(AnalysisConfig $config): array
    {
        // The returned list is already filtered to rules that can run for this config.
        return array_values(array_filter(
                                $this->rules,
                                static function (RuleInterface|ProjectRuleInterface $rule) use ($config): bool {
                                    $definition = $rule->definition();

                                    // Enabled rules must pass both per-rule toggles and selection filters.
                                    return $config->ruleSettings($definition->id)->enabled
                                           && $config->ruleSelection()->allows($definition);
                                },
                            ));
    }

    /**
     * Check whether the effective config enables at least one project-level rule.
     *
     * @param AnalysisConfig $config Config used to filter registered rules.
     *
     * @return bool - true when at least one enabled rule needs whole-project context; false when a per-unit pass suffices
     */
    public function hasEnabledProjectRules(AnalysisConfig $config): bool
    {
        foreach ($this->enabledRules($config) as $rule) {
            if ($rule instanceof ProjectRuleInterface) {
                // One project rule is enough to require whole-project context.
                return true;
            }
        }

        // Pure per-unit rule sets can be analysed without project-level context.
        return false;
    }

    /**
     * Determine whether the enabled rule set is fully streaming-capable.
     *
     * A rule set is streaming-capable when every enabled project rule
     * implements ProjectRuleAccumulator. Per-unit rules are always
     * streaming-friendly.
     *
     * @param RuleContext $ruleContext Rule execution context.
     *
     * @return bool - true when the run can stream unit-by-unit; false when a legacy project rule forces buffering all units
     */
    public function supportsStreaming(RuleContext $ruleContext): bool
    {
        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            if ($rule instanceof ProjectRuleInterface && !$rule instanceof ProjectRuleAccumulator) {
                // Legacy project rules need all units at once, so streaming is unavailable.
                return false;
            }
        }

        // Per-unit rules and accumulator project rules can run incrementally.
        return true;
    }

    /**
     * Initialise project-rule accumulators before a streaming analysis pass.
     *
     * @param RuleContext $ruleContext Rule execution context.
     *
     * @return void
     */
    public function beginStreaming(RuleContext $ruleContext): void
    {
        foreach ($this->enabledRules($ruleContext->config) as $rule) {
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
     * @param AnalysisUnit            $analysisUnit       Parsed unit to analyse.
     * @param RuleContext             $ruleContext        Rule execution context.
     * @param RuleRunnerObserver|null $ruleRunnerObserver Optional per-rule timing hook.
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

        // Streaming callers receive only immediate file-scoped findings for this unit.
        return $findings;
    }

    /**
     * Run only the per-unit (file-scoped) rules against a single unit.
     *
     * @param AnalysisUnit            $analysisUnit       Parsed unit to analyse.
     * @param RuleContext             $ruleContext        Rule execution context.
     * @param RuleRunnerObserver|null $ruleRunnerObserver Optional per-rule timing hook.
     *
     * @return list<Finding> - findings from per-unit rules in rule-execution order, not yet deduped or final-sorted
     */
    private function runPerUnitRules(
        AnalysisUnit        $analysisUnit,
        RuleContext         $ruleContext,
        ?RuleRunnerObserver $ruleRunnerObserver,
    ): array {
        if ($analysisUnit->hasParseErrors()) {
            // Parse diagnostics are already recorded; rules should not inspect invalid AST.
            return [];
        }

        $findings = [];
        $isPhp    = $analysisUnit->file->isPhp();

        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            if (!$rule instanceof RuleInterface) {
                continue;
            }
            if (!$isPhp && !$rule instanceof SourceTextRuleInterface) {
                continue;
            }

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

        // Per-unit findings are not final-sorted until the caller finalizes the full run.
        return $findings;
    }

    /**
     * Push one unit through every enabled streaming project rule.
     *
     * @param AnalysisUnit            $analysisUnit       Parsed unit to accumulate.
     * @param RuleContext             $ruleContext        Rule execution context.
     * @param RuleRunnerObserver|null $ruleRunnerObserver Optional per-rule timing hook.
     *
     * @return void
     */
    private function accumulateForUnit(
        AnalysisUnit        $analysisUnit,
        RuleContext         $ruleContext,
        ?RuleRunnerObserver $ruleRunnerObserver,
    ): void {
        if ($analysisUnit->hasParseErrors() || !$analysisUnit->file->isPhp()) {
            // Project accumulators consume only parse-clean PHP units.
            return;
        }

        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            if (!$rule instanceof ProjectRuleAccumulator) {
                continue;
            }

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
     * @param RuleContext             $ruleContext        Rule execution context.
     * @param RuleRunnerObserver|null $ruleRunnerObserver Optional per-rule timing hook.
     *
     * @return list<Finding> - project-level findings flushed from accumulator state; empty when no accumulators ran or matched
     */
    public function endStreaming(
        RuleContext         $ruleContext,
        ?RuleRunnerObserver $ruleRunnerObserver = null,
    ): array {
        $findings = [];

        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            if (!$rule instanceof ProjectRuleAccumulator) {
                continue;
            }

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

        // Project accumulator findings are collected after all units have been seen.
        return $findings;
    }

    /**
     * Apply the canonical ordering and dedupe pass to a streaming run's
     * collected findings. Callers that drive analyseUnit() / endStreaming()
     * themselves should call this once at the end so the output matches
     * the non-streaming analyse() flow byte-for-byte.
     *
     * @param list<Finding> $findings
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
                                                                               $leftFinding->line ?? 0,
                                                                               $leftFinding->ruleId,
                                                                               $leftFinding->message,
                                                                           ] <=> [
                                                                               $rightFinding->filePath,
                                                                               $rightFinding->line ?? 0,
                                                                               $rightFinding->ruleId,
                                                                               $rightFinding->message,
                                                                           ],
        );

        // Final ordering is part of the stable report contract.
        return $findings;
    }

    /**
     * Run all enabled file and project rules against parsed units.
     *
     * @param list<AnalysisUnit>      $units                           Parsed units to analyse with file-scoped rules.
     * @param RuleContext             $ruleContext                     Rule execution context.
     * @param list<AnalysisUnit>|null $projectUnits                    Parsed units available to project-level rules.
     * @param RuleRunnerObserver|null $ruleRunnerObserver              Optional per-rule timing hook; default analyse runs leave this null.
     * @param bool                    $shouldReleaseUnitsAfterAnalysis Whether units can release AST contents after analysis.
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
                              && $legacyProjectRules === []
                              && $projectUnits === null;

        $this->beginStreaming($ruleContext);

        $findings = [];
        foreach ($units as $unit) {
            array_push($findings, ...$this->runPerUnitRules($unit, $ruleContext, $ruleRunnerObserver));
            if ($projectUnits === null) {
                $this->accumulateForUnit($unit, $ruleContext, $ruleRunnerObserver);
            }
            if ($canReleaseUnits) {
                $unit->release();
            }
        }

        if ($projectUnits !== null) {
            foreach ($projectUnits as $contextUnit) {
                $this->accumulateForUnit($contextUnit, $ruleContext, $ruleRunnerObserver);
            }
        }

        array_push($findings, ...$this->endStreaming($ruleContext, $ruleRunnerObserver));

        if ($legacyProjectRules !== []) {
            array_push($findings, ...$this->runLegacyProjectRules(
                $legacyProjectRules,
                $projectUnits ?? $units,
                $ruleContext,
                $ruleRunnerObserver,
            ));
        }

        // The finalized list includes per-unit, accumulator, and legacy project-rule findings.
        return $this->finalizeFindings($findings);
    }

    /**
     * Find enabled project rules that still need the full unit list.
     *
     * @param RuleContext $ruleContext Rule execution context.
     *
     * @return list<ProjectRuleInterface> - enabled project rules lacking accumulator support, which must run with the full unit list; empty when all
     *                                    stream
     */
    private function legacyProjectRules(RuleContext $ruleContext): array
    {
        $rules = [];
        foreach ($this->enabledRules($ruleContext->config) as $rule) {
            if ($rule instanceof ProjectRuleInterface && !$rule instanceof ProjectRuleAccumulator) {
                $rules[] = $rule;
            }
        }

        // Legacy rules run after the per-unit phase with the complete context.
        return $rules;
    }

    /**
     * Run project-level rules that need the full analysis context.
     *
     * @param list<ProjectRuleInterface> $rules              Project rules to run.
     * @param list<AnalysisUnit>         $contextUnits       Candidate units available to project rules.
     * @param RuleContext                $ruleContext        Rule execution context.
     * @param RuleRunnerObserver|null    $ruleRunnerObserver Optional per-rule timing hook.
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

        if ($analyseableUnits === []) {
            // Project rules reason only over parse-clean PHP units.
            return [];
        }

        $findings = [];
        foreach ($rules as $rule) {
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

        // Legacy project findings join the per-unit findings before final sorting.
        return $findings;
    }

    /**
     * Build deduplicate findings for the component.
     *
     * @param list<Finding> $findings Findings to collapse by full reporting identity.
     *
     * @return list<Finding> - input order preserved with later exact-identity duplicates dropped (first occurrence wins)
     */
    private function deduplicateFindings(array $findings): array
    {
        $seen           = [];
        $uniqueFindings = [];

        foreach ($findings as $finding) {
            $key = implode("\0", [
                $finding->ruleId,
                $finding->filePath,
                (string)($finding->line ?? ''),
                (string)($finding->endLine ?? ''),
                (string)($finding->column ?? ''),
                $finding->symbol ?? '',
                $finding->message,
                $finding->metadata === [] ? '' : serialize($finding->metadata),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key]       = true;
            $uniqueFindings[] = $finding;
        }

        // First occurrence wins so deterministic rule order remains stable.
        return $uniqueFindings;
    }

    /**
     * Keep only the highest-priority naming finding when multiple naming rules
     * report the same identifier at the same source location.
     *
     * @param list<Finding> $findings Findings that may contain overlapping naming reports.
     *
     * @return list<Finding> - relative order preserved, keeping only the highest-priority naming finding per overlapping identifier
     */
    private function deduplicateNamingFindings(array $findings): array
    {
        $bestByIdentifier = [];
        $selectedIndexes  = [];

        foreach ($findings as $index => $finding) {
            $key = $this->namingOverlapKey($finding);
            if ($key === null) {
                $selectedIndexes[$index] = true;
                continue;
            }

            $priority = self::NAMING_RULE_PRIORITY[$finding->ruleId];
            if (!isset($bestByIdentifier[$key]) || $priority < $bestByIdentifier[$key]['priority']) {
                $bestByIdentifier[$key] = ['index' => $index, 'priority' => $priority];
            }
        }

        foreach ($bestByIdentifier as $selected) {
            $selectedIndexes[$selected['index']] = true;
        }

        ksort($selectedIndexes, SORT_NUMERIC);

        // Preserve original relative order for the selected findings.
        return array_values(array_intersect_key($findings, $selectedIndexes));
    }

    /**
     * Build the cross-rule identifier key used to collapse duplicate naming findings.
     *
     * @param Finding $finding Finding to classify for naming-rule overlap.
     *
     * @return string|null - overlap-bucket key (file, line, column, symbol, identifier); null when the finding cannot participate in naming dedup
     */
    private function namingOverlapKey(Finding $finding): ?string
    {
        if (!isset(self::NAMING_RULE_PRIORITY[$finding->ruleId])) {
            // Non-naming findings never participate in naming-overlap suppression.
            return null;
        }

        $identifierName = $this->findingIdentifierName($finding);
        if ($identifierName === null) {
            // A naming finding without an identifier cannot be safely collapsed.
            return null;
        }

        // File, location, symbol, and identifier define the overlap bucket.
        return implode("\0", [
            $finding->filePath,
            (string)($finding->line ?? ''),
            (string)($finding->column ?? ''),
            $finding->symbol ?? '',
            strtolower($identifierName),
        ]);
    }

    /**
     * Extract the identifier name from finding metadata.
     *
     * @param Finding $finding Finding whose metadata may carry an identifier.
     *
     * @return string|null - identifier from metadata, falling back to the finding symbol; null when neither is present
     */
    private function findingIdentifierName(Finding $finding): ?string
    {
        foreach (['identifierName', 'variable', 'parameter'] as $metadataKey) {
            $metadataValue = $finding->metadata[$metadataKey] ?? null;
            if (is_string($metadataValue)) {
                // Explicit metadata is more precise than the optional finding symbol.
                return $metadataValue;
            }
        }

        // Symbol is the fallback for naming rules that report at declaration level.
        return $finding->symbol;
    }
}
