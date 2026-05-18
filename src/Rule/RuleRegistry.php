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
use GruffPhp\Rule\Complexity\NpathComplexityRule;
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
use GruffPhp\Rule\Docs\StaleParamTagRule;
use GruffPhp\Rule\Docs\TodoDensityRule;
use GruffPhp\Rule\Docs\UselessPhpdocRule;
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
use GruffPhp\Rule\Naming\ParameterTypeNameRule;
use GruffPhp\Rule\Naming\ShortVariableRule;
use GruffPhp\Rule\Naming\SuffixHungarianRule;
use GruffPhp\Rule\Naming\TestNamingConsistencyRule;
use GruffPhp\Rule\Security\DangerousFunctionCallRule;
use GruffPhp\Rule\Security\DisabledSslVerificationRule;
use GruffPhp\Rule\Security\ErrorSuppressionRule;
use GruffPhp\Rule\Security\ExtractCompactUserInputRule;
use GruffPhp\Rule\Security\HeaderInjectionRule;
use GruffPhp\Rule\Security\InsecureRandomRule;
use GruffPhp\Rule\Security\SilentCatchRule;
use GruffPhp\Rule\Security\SqlConcatenationRule;
use GruffPhp\Rule\Security\UnsafeUnserializeRule;
use GruffPhp\Rule\Security\VariableIncludeRule;
use GruffPhp\Rule\Security\WeakCryptoRule;
use GruffPhp\Rule\SensitiveData\ApiKeyPatternRule;
use GruffPhp\Rule\SensitiveData\AwsAccessKeyRule;
use GruffPhp\Rule\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rule\SensitiveData\HardcodedEnvValueRule;
use GruffPhp\Rule\SensitiveData\HighEntropyStringRule;
use GruffPhp\Rule\SensitiveData\JwtTokenRule;
use GruffPhp\Rule\SensitiveData\PhiPatternRule;
use GruffPhp\Rule\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rule\SensitiveData\PrivateKeyRule;
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
use GruffPhp\Rule\TestQuality\LoopInTestRule;
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
        'naming.class-file-mismatch' => 0,
        'naming.confusing-name' => 1,
        'naming.negative-boolean' => 2,
        'naming.boolean-prefix' => 3,
        'naming.parameter-type-name' => 4,
        'naming.identifier-quality' => 5,
        'naming.hungarian-notation' => 6,
        'naming.suffix-hungarian' => 7,
        'naming.short-variable' => 8,
        'naming.abbreviation-allowlist' => 9,
    ];

    /** @var array<string, RuleInterface|ProjectRuleInterface> */
    private readonly array $rules;

    /**
     * @param list<RuleInterface|ProjectRuleInterface> $rules Rule instances to index by id.
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
     * @return self Registry indexed by rule id.
     */
    public static function defaults(): self
    {
        return new self([
            new CognitiveComplexityRule(),
            new CyclomaticComplexityRule(),
            new HalsteadVolumeRule(),
            new MaintainabilityIndexRule(),
            new NestingDepthRule(),
            new NpathComplexityRule(),
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
            new ParameterTypeNameRule(),
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
            new HardcodedEnvValueRule(),
            new HighEntropyStringRule(),
            new JwtTokenRule(),
            new PhiPatternRule(),
            new PiiTestFixtureRule(),
            new PrivateKeyRule(),
            new DangerousFunctionCallRule(),
            new DisabledSslVerificationRule(),
            new ErrorSuppressionRule(),
            new ExtractCompactUserInputRule(),
            new HeaderInjectionRule(),
            new InsecureRandomRule(),
            new SilentCatchRule(),
            new SqlConcatenationRule(),
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
            new LoopInTestRule(),
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
            new StaleParamTagRule(),
            new TodoDensityRule(),
            new UselessPhpdocRule(),
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
     * @return list<RuleInterface|ProjectRuleInterface>
     */
    public function all(): array
    {
        return array_values($this->rules);
    }

    /**
     * Check whether a rule id is registered.
     *
     * @param string $ruleId Rule identifier to check.
     * @return bool True when the rule exists in the registry.
     */
    public function has(string $ruleId): bool
    {
        return isset($this->rules[$ruleId]);
    }

    /**
     * Return a registered rule by id.
     *
     * @param string $ruleId Rule identifier to look up.
     * @throws InvalidArgumentException When the rule id is unknown.
     * @return RuleInterface|ProjectRuleInterface Matching rule instance.
     */
    public function get(string $ruleId): RuleInterface|ProjectRuleInterface
    {
        return $this->rules[$ruleId]
            ?? throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
    }

    /**
     * Return rules enabled by the effective analysis config.
     *
     * @param AnalysisConfig $config Config used to filter registered rules.
     * @return list<RuleInterface|ProjectRuleInterface> Enabled rule instances.
     */
    public function enabledRules(AnalysisConfig $config): array
    {
        return array_values(array_filter(
            $this->rules,
            static function (RuleInterface|ProjectRuleInterface $rule) use ($config): bool {
                $definition = $rule->definition();

                return $config->ruleSettings($definition->id)->enabled
                    && $config->ruleSelection()->allows($definition);
            },
        ));
    }

    /**
     * Check whether the effective config enables at least one project-level rule.
     *
     * @param AnalysisConfig $config Config used to filter registered rules.
     * @return bool True when project-level analysis needs complete project context.
     */
    public function hasEnabledProjectRules(AnalysisConfig $config): bool
    {
        foreach ($this->enabledRules($config) as $rule) {
            if ($rule instanceof ProjectRuleInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run all enabled file and project rules against parsed units.
     *
     * @param list<AnalysisUnit>      $units              Parsed units to analyse with file-scoped rules.
     * @param RuleContext             $context            Rule execution context.
     * @param list<AnalysisUnit>|null $projectUnits       Parsed units available to project-level rules.
     * @param RuleRunnerObserver|null $ruleRunnerObserver Optional per-rule timing hook; default analyse runs leave this null.
     * @return list<Finding> Findings produced by enabled rules.
     */
    public function analyse(
        array $units,
        RuleContext $context,
        ?array $projectUnits = null,
        ?RuleRunnerObserver $ruleRunnerObserver = null,
    ): array {
        $findings     = [];
        $enabledRules = $this->enabledRules($context->config);

        foreach ($units as $unit) {
            if ($unit->hasParseErrors()) {
                continue;
            }

            $isPhp = $unit->file->isPhp();

            foreach ($enabledRules as $rule) {
                if (!$rule instanceof RuleInterface) {
                    continue;
                }

                if (!$isPhp && !$rule instanceof SourceTextRuleInterface) {
                    continue;
                }

                if ($ruleRunnerObserver === null) {
                    array_push($findings, ...$rule->analyse($unit, $context));
                    continue;
                }

                $ruleId       = $rule->definition()->id;
                $started      = hrtime(true);
                $ruleFindings = $rule->analyse($unit, $context);
                $ruleRunnerObserver->onRuleExecuted($ruleId, hrtime(true) - $started);
                array_push($findings, ...$ruleFindings);
            }
        }

        $projectUnits ??= $units;
        $analyseableUnits = array_values(array_filter(
            $projectUnits,
            static fn (AnalysisUnit $unit): bool => !$unit->hasParseErrors() && $unit->file->isPhp(),
        ));

        if ($analyseableUnits !== []) {
            foreach ($enabledRules as $rule) {
                if (!$rule instanceof ProjectRuleInterface) {
                    continue;
                }

                if ($ruleRunnerObserver === null) {
                    array_push($findings, ...$rule->analyseProject($analyseableUnits, $context));
                    continue;
                }

                $ruleId          = $rule->definition()->id;
                $started         = hrtime(true);
                $projectFindings = $rule->analyseProject($analyseableUnits, $context);
                $ruleRunnerObserver->onRuleExecuted($ruleId, hrtime(true) - $started);
                array_push($findings, ...$projectFindings);
            }
        }

        $findings = $this->deduplicateNamingFindings($this->deduplicateFindings($findings));

        usort(
            $findings,
            static fn (Finding $findingLeft, Finding $findingRight): int => [
                $findingLeft->filePath,
                $findingLeft->line ?? 0,
                $findingLeft->ruleId,
                $findingLeft->message,
            ] <=> [
                $findingRight->filePath,
                $findingRight->line ?? 0,
                $findingRight->ruleId,
                $findingRight->message,
            ],
        );

        return $findings;
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function deduplicateFindings(array $findings): array
    {
        $seen           = [];
        $uniqueFindings = [];

        foreach ($findings as $finding) {
            $key = implode("\0", [
                $finding->ruleId,
                $finding->filePath,
                (string) ($finding->line ?? ''),
                (string) ($finding->endLine ?? ''),
                (string) ($finding->column ?? ''),
                $finding->symbol ?? '',
                $finding->message,
                json_encode($finding->metadata) ?: '',
            ]);

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
     * @param list<Finding> $findings
     * @return list<Finding>
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

        return array_values(array_intersect_key($findings, $selectedIndexes));
    }

    private function namingOverlapKey(Finding $finding): ?string
    {
        if (!isset(self::NAMING_RULE_PRIORITY[$finding->ruleId])) {
            return null;
        }

        $identifierName = $this->findingIdentifierName($finding);
        if ($identifierName === null) {
            return null;
        }

        return implode("\0", [
            $finding->filePath,
            (string) ($finding->line ?? ''),
            (string) ($finding->column ?? ''),
            $finding->symbol ?? '',
            strtolower($identifierName),
        ]);
    }

    private function findingIdentifierName(Finding $finding): ?string
    {
        foreach (['identifierName', 'variable', 'parameter'] as $metadataKey) {
            $value = $finding->metadata[$metadataKey] ?? null;
            if (is_string($value)) {
                return $value;
            }
        }

        return $finding->symbol;
    }
}
