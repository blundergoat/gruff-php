<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CognitiveComplexityRule;
use GruffPhp\Rule\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rule\Docs\MissingParamTagRule;
use GruffPhp\Rule\Docs\MissingPublicPhpdocRule;
use GruffPhp\Rule\Docs\MissingReadmeRule;
use GruffPhp\Rule\Docs\MissingReturnTagRule;
use GruffPhp\Rule\Docs\MissingThrowsTagRule;
use GruffPhp\Rule\Docs\StaleParamTagRule;
use GruffPhp\Rule\Docs\TodoDensityRule;
use GruffPhp\Rule\Docs\UselessPhpdocRule;
use GruffPhp\Rule\Naming\BooleanPrefixRule;
use GruffPhp\Rule\Naming\ClassFileMismatchRule;
use GruffPhp\Rule\Naming\ConfusingNameRule;
use GruffPhp\Rule\Naming\GenericMethodNameRule;
use GruffPhp\Rule\Naming\HungarianNotationRule;
use GruffPhp\Rule\Naming\ShortVariableRule;
use GruffPhp\Rule\Naming\TestNamingConsistencyRule;
use GruffPhp\Rule\DeadCode\UnusedPrivatePropertyRule;
use GruffPhp\Rule\Modernisation\ConstructorPromotionCandidateRule;
use GruffPhp\Rule\Modernisation\EnumCandidateRule;
use GruffPhp\Rule\Modernisation\FirstClassCallableCandidateRule;
use GruffPhp\Rule\Modernisation\ForbiddenGlobalAccessRule;
use GruffPhp\Rule\Modernisation\MatchExpressionCandidateRule;
use GruffPhp\Rule\Modernisation\MixedTypeOveruseRule;
use GruffPhp\Rule\Modernisation\NamedArgumentOpportunityRule;
use GruffPhp\Rule\Modernisation\PublicPropertyRule;
use GruffPhp\Rule\Modernisation\ReadonlyPropertyCandidateRule;
use GruffPhp\Rule\Secrets\ApiKeyPatternRule;
use GruffPhp\Rule\Secrets\AwsAccessKeyRule;
use GruffPhp\Rule\Secrets\DatabaseUrlPasswordRule;
use GruffPhp\Rule\Secrets\HardcodedEnvValueRule;
use GruffPhp\Rule\Secrets\HighEntropyStringRule;
use GruffPhp\Rule\Secrets\JwtTokenRule;
use GruffPhp\Rule\Secrets\PhiPatternRule;
use GruffPhp\Rule\Secrets\PiiTestFixtureRule;
use GruffPhp\Rule\Secrets\PrivateKeyRule;
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
use GruffPhp\Rule\TestQuality\ConditionalTestLogicRule;
use GruffPhp\Rule\TestQuality\DataProviderAnnotationRule;
use GruffPhp\Rule\TestQuality\EagerTestRule;
use GruffPhp\Rule\TestQuality\ExcessiveMockingRule;
use GruffPhp\Rule\TestQuality\LoopInTestRule;
use GruffPhp\Rule\TestQuality\MagicNumberAssertionRule;
use GruffPhp\Rule\TestQuality\MockOnlyTestRule;
use GruffPhp\Rule\TestQuality\MysteryGuestRule;
use GruffPhp\Rule\TestQuality\NoAssertionsRule;
use GruffPhp\Rule\TestQuality\PrivateReflectionRule;
use GruffPhp\Rule\TestQuality\SetupBloatRule;
use GruffPhp\Rule\TestQuality\SkippedWithoutReasonRule;
use GruffPhp\Rule\TestQuality\SleepInTestRule;
use GruffPhp\Rule\TestQuality\SutNotCalledRule;
use GruffPhp\Rule\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rule\TestQuality\TestNamingConsistencyRule as TestQualityNamingConsistencyRule;
use GruffPhp\Rule\TestQuality\TrivialAssertionRule;
use GruffPhp\Rule\TestQuality\TrivialSnapshotRule;
use GruffPhp\Rule\Waste\CommentedOutCodeRule;
use GruffPhp\Rule\Waste\EmptyClassRule;
use GruffPhp\Rule\Waste\EmptyMethodRule;
use GruffPhp\Rule\Waste\UnreachableCodeRule;
use GruffPhp\Rule\Waste\UnusedImportRule;
use GruffPhp\Rule\Waste\UnusedParameterRule;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\Complexity\HalsteadVolumeRule;
use GruffPhp\Rule\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rule\Complexity\NestingDepthRule;
use GruffPhp\Rule\Complexity\NpathComplexityRule;
use GruffPhp\Rule\Size\AverageMethodLengthRule;
use GruffPhp\Rule\Size\ClassLengthRule;
use GruffPhp\Rule\Size\FileLengthRule;
use GruffPhp\Rule\Size\MethodLengthRule;
use GruffPhp\Rule\Size\ParameterCountRule;
use GruffPhp\Rule\Size\PropertyCountRule;
use GruffPhp\Rule\Size\PublicMethodCountRule;
use InvalidArgumentException;

final class RuleRegistry
{
    /** @var array<string, RuleInterface> */
    private array $rules;

    /**
     * @param list<RuleInterface> $rules
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
            new UnreachableCodeRule(),
            new UnusedImportRule(),
            new UnusedParameterRule(),
            new BooleanPrefixRule(),
            new ClassFileMismatchRule(),
            new ConfusingNameRule(),
            new GenericMethodNameRule(),
            new HungarianNotationRule(),
            new ShortVariableRule(),
            new TestNamingConsistencyRule(),
            new ConstructorPromotionCandidateRule(),
            new EnumCandidateRule(),
            new FirstClassCallableCandidateRule(),
            new ForbiddenGlobalAccessRule(),
            new MatchExpressionCandidateRule(),
            new MixedTypeOveruseRule(),
            new NamedArgumentOpportunityRule(),
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
            new ExcessiveMockingRule(),
            new LoopInTestRule(),
            new MagicNumberAssertionRule(),
            new MockOnlyTestRule(),
            new MysteryGuestRule(),
            new NoAssertionsRule(),
            new PrivateReflectionRule(),
            new SetupBloatRule(),
            new SkippedWithoutReasonRule(),
            new SleepInTestRule(),
            new SutNotCalledRule(),
            new TestLongerThanSutRule(),
            new TestQualityNamingConsistencyRule(),
            new TrivialAssertionRule(),
            new TrivialSnapshotRule(),
            new MissingParamTagRule(),
            new MissingPublicPhpdocRule(),
            new MissingReadmeRule(),
            new MissingReturnTagRule(),
            new MissingThrowsTagRule(),
            new StaleParamTagRule(),
            new TodoDensityRule(),
            new UselessPhpdocRule(),
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
     * @return list<RuleInterface>
     */
    public function all(): array
    {
        return array_values($this->rules);
    }

    public function has(string $ruleId): bool
    {
        return isset($this->rules[$ruleId]);
    }

    public function get(string $ruleId): RuleInterface
    {
        return $this->rules[$ruleId]
            ?? throw new InvalidArgumentException(sprintf('Unknown rule id "%s".', $ruleId));
    }

    /**
     * @return list<RuleInterface>
     */
    public function enabledRules(AnalysisConfig $config): array
    {
        return array_values(array_filter(
            $this->rules,
            static function (RuleInterface $rule) use ($config): bool {
                $definition = $rule->definition();

                return $config->ruleSettings($definition->id)->enabled
                    && $config->ruleSelection()->allows($definition);
            },
        ));
    }

    /**
     * @param list<AnalysisUnit> $units
     * @return list<Finding>
     */
    public function analyse(array $units, RuleContext $context): array
    {
        $findings = [];

        foreach ($units as $unit) {
            if ($unit->hasParseErrors()) {
                continue;
            }

            foreach ($this->enabledRules($context->config) as $rule) {
                if (!$unit->file->isPhp() && !$rule instanceof SourceTextRuleInterface) {
                    continue;
                }

                array_push($findings, ...$rule->analyse($unit, $context));
            }
        }

        $findings = $this->deduplicateFindings($findings);

        usort(
            $findings,
            static fn (Finding $left, Finding $right): int => [
                $left->filePath,
                $left->line ?? 0,
                $left->ruleId,
                $left->message,
            ] <=> [
                $right->filePath,
                $right->line ?? 0,
                $right->ruleId,
                $right->message,
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
        $seen = [];
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

            $seen[$key] = true;
            $uniqueFindings[] = $finding;
        }

        return $uniqueFindings;
    }
}
