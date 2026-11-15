<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\DangerousFunctionCallRule;
use GruffPhp\Rules\Security\DebugModeEnabledRule;
use GruffPhp\Rules\Security\DisabledSslVerificationRule;
use GruffPhp\Rules\Security\ErrorSuppressionRule;
use GruffPhp\Rules\Security\ExtractCompactUserInputRule;
use GruffPhp\Rules\Security\HeaderInjectionRule;
use GruffPhp\Rules\Security\ProcessCommandConstructionRule;
use GruffPhp\Rules\Security\RequestControlledUrlRule;
use GruffPhp\Rules\Security\SilentCatchRule;
use GruffPhp\Rules\Security\SqlConcatenationRule;
use GruffPhp\Rules\Security\UnsafeArchiveExtractionRule;
use GruffPhp\Rules\Security\UnsafeXmlLoadingRule;
use GruffPhp\Rules\Security\UnsafeUnserializeRule;
use GruffPhp\Engine\Source\SourceFile;
use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks security-rule findings for execution, request data, and configured rule settings.
 * Pins finding counts and source lines with the full registry and explicit fixture configuration.
 * Callable and escaping controls check which patterns the rules leave unreported.
 */
final class SecurityRulesTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Create the parser used to load security fixture files.
     *
     * @return void - Initialises the parser for each test.
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    /**
     * Assert dangerous-execution findings and representative function metadata.
     *
     * @return void - Asserts nine findings, a Warning first finding, and the listed execution categories.
     */
    public function testDangerousExecutionPatternsDetected(): void
    {
        $findings = $this->findingsForRule($this->dangerousExecutionUnit(), DangerousFunctionCallRule::ID);

        self::assertCount(9, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);

        $functions = array_map(static fn(Finding $finding): mixed => $finding->metadata['function'] ?? null, $findings);
        self::assertContains('exec', $functions);
        self::assertContains('shell_exec', $functions);
        self::assertContains('eval', $functions);
        self::assertContains('assert string evaluation', $functions);
        self::assertContains('dynamic function call', $functions);
    }

    /**
     * Assert normalization of a configured function and retention of the built-in exec finding.
     *
     * @return void - Asserts the custom_runner and exec findings in source order.
     */
    public function testAdditionalDangerousFunctionsAreAdditiveAndNormalized(): void
    {
        $unit = $this->parseSource(
            implode("\n", [
                '<?php',
                'function runConfiguredDangerousFunctions(string $command): void',
                '{',
                '    custom_runner($command);',
                '    ' . 'ex' . 'ec' . '($command);',
                '    harmless_runner($command);',
                '}',
            ]) . "\n",
            'tests/Fixtures/Security/inline-configured-dangerous-functions.php',
        );

        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $settings = $config->ruleSettings(DangerousFunctionCallRule::ID);
        $config   = $config->withRuleSettings(
            DangerousFunctionCallRule::ID,
            new RuleSettings(
                true,
                $settings->thresholds,
                [...$settings->options, 'additionalFunctions' => [' CuStOm_RuNnEr ']],
            ),
        );

        $findings = $this->findingsForRule($unit, DangerousFunctionCallRule::ID, $config);
        self::assertSame(
            ['custom_runner', 'exec'],
            array_map(static fn (Finding $finding): mixed => $finding->metadata['function'] ?? null, $findings),
        );
    }

    /**
     * Verify typed callable invocations are not dangerous dynamic calls.
     *
     * @return void - Asserts absence of dynamic-call findings and presence of the system finding.
     */
    public function testTypedCallableInvocationsAreNotDangerousDynamicCalls(): void
    {
        $findings  = $this->findingsForRule($this->typedCallableUnit(), DangerousFunctionCallRule::ID);
        $functions = array_map(static fn(Finding $finding): mixed => $finding->metadata['function'] ?? null, $findings);

        self::assertNotContains('dynamic function call', $functions);
        self::assertContains('system', $functions);
    }

    /**
     * Verify local closure invocations are not treated like arbitrary dynamic calls.
     *
     * @return void - Asserts that the literal-bound system call is the only finding.
     */
    public function testLocalClosureInvocationsAreNotDangerousDynamicCalls(): void
    {
        $findings  = $this->findingsForRule($this->localClosureUnit(), DangerousFunctionCallRule::ID);
        $functions = array_map(static fn(Finding $finding): mixed => $finding->metadata['function'] ?? null, $findings);

        // The dynamic local is assigned only 'system', so the rule treats its invocation as a direct system() call.
        self::assertSame(['system'], $functions);
    }

    /**
     * Verify callable collections are not treated like arbitrary dynamic calls.
     *
     * @return void - Asserts that the callable-collection fixture has no dangerous-function findings.
     */
    public function testCallableCollectionInvocationsAreNotDangerousDynamicCalls(): void
    {
        $findings = $this->findingsForRule($this->callableCollectionUnit(), DangerousFunctionCallRule::ID);

        self::assertSame([], $findings);
    }

    /**
     * Assert the expected counts for the selected security heuristics.
     *
     * @return void - Asserts the five rule-specific finding counts.
     */
    public function testRequestDataSecurityHeuristicsDetected(): void
    {
        $findings = $this->analyse('data-flow-heuristics.php');

        self::assertRuleCount(UnsafeUnserializeRule::ID, 1, $findings);
        self::assertRuleCount(HeaderInjectionRule::ID, 2, $findings);
        self::assertRuleCount(ExtractCompactUserInputRule::ID, 3, $findings);
        self::assertRuleCount(ErrorSuppressionRule::ID, 1, $findings);
        self::assertRuleCount(SilentCatchRule::ID, 1, $findings);
    }

    /**
     * Provide precision fixtures whose exact finding lines pin one rule's attack-shape coverage each.
     *
     * @return array<string, array{string, string, list<int>}> - Fixture, rule id, and expected finding lines keyed by case label.
     */
    public static function precisionFixtureCases(): array
    {
        return [
            'sql concatenation attack shapes' => ['sql-concatenation-precision.php', SqlConcatenationRule::ID, [7, 8, 9, 10, 25]],
            // In escapeSplicedSinkValues(), integer casts and unquoted quoteString()/backquote() values stay quiet.
            // Bare $id, ->quote(), a construction inside sprintf(), and quoteString() inside an open quote report.

            'procedural sql sinks follow one unambiguous local assignment' => [
                'procedural-sink-precision.php', SqlConcatenationRule::ID, [8, 21, 22, 23, 24, 59, 60, 90, 91, 94, 95],
            ],
            // Integer casts and escapeshellarg() outside open quotes stay quiet, including concatenation inside its argument.
            // Bare values, escapeshellcmd(), nested command construction, and escapeshellarg() inside an open quote report.

            //
            // Locals assigned only plain words stay quiet; spaces, quotes, raw ternary arms, and short ternaries report.
            // The preg_match() output array and a local overwritten after the sink also report.
            'procedural command sinks flag dynamic command strings' => [
                'procedural-sink-precision.php', ProcessCommandConstructionRule::ID, [13, 61, 75, 76, 82, 85, 86, 92, 93, 105, 107, 109, 111, 114, 116],
            ],
            // Conditional rebinding retains possible XML receiver evidence, including in sibling branches.
            // Conditional XML construction also reports; rebinding on the sink's own path stays quiet.
            'xml loaders need xml receivers' => ['xml-receiver-gating.php', UnsafeXmlLoadingRule::ID, [22, 27, 33, 38, 54, 64, 82]],
            // Each pair is the same sink written positionally then with named arguments only. Both lines must
            // appear so a regression in named-argument resolution cannot pass unnoticed.
            'named header arguments resolve' => ['named-argument-sinks.php', HeaderInjectionRule::ID, [7, 8]],
            // Explanatory comments, including "can't", and later reads of the caught value stay quiet.
            //
            // Stock phrases, bare reason words, TODOs, disabled code, and unexplained silence report.
            // Caught values also report when unused, replaced, unset, probed only by isset(), or read only in a nested function.
            'silent catches that explain or handle the exception stay quiet' => [
                'silent-catch-precision.php', SilentCatchRule::ID,
                [53, 58, 63, 68, 73, 78, 121, 125, 134, 140, 145, 152, 159],
            ],
            // Protocol-token status lines and values the rule recognises as integers stay quiet.
            // Look-alikes, uncertain or external writes, variable-variables, and writes by extract(), eval(), or include report.
            // Locals aliased through array items and superglobal names also report.


            'header values that cannot carry CR/LF stay quiet' => [
                'header-value-precision.php', HeaderInjectionRule::ID,
                [23, 24, 25, 26, 27, 56, 61, 63, 65, 79, 93, 97, 100, 105, 119, 138, 140, 142, 144, 160, 174, 188, 200, 202],
            ],
            'named unserialize arguments resolve' => ['named-argument-sinks.php', UnsafeUnserializeRule::ID, [13, 14]],
            'named extract arguments resolve' => ['named-argument-sinks.php', ExtractCompactUserInputRule::ID, [19, 20]],
            'named ini_set arguments resolve' => ['named-argument-sinks.php', DebugModeEnabledRule::ID, [25, 26]],
            'named curl_setopt arguments resolve' => ['named-argument-sinks.php', DisabledSslVerificationRule::ID, [31, 32]],
            'named xml arguments resolve' => ['named-argument-sinks.php', UnsafeXmlLoadingRule::ID, [43, 44]],
            // Callable evidence does not cross sibling functions; request-selected targets report even through callable wrappers.
            // Syntax-only guards, callable-string annotations, shadowed names, unrelated receivers, reassignment, and spread arrays report.
            // Early-return guards remain unrecognised; closure and callable shapes recognised by the rule stay quiet.
            'dynamic calls prove callability the way PHP does' => ['dynamic-call-precision.php', DangerousFunctionCallRule::ID, [104, 111, 112, 114, 124, 132, 137, 140, 141, 144, 146, 154, 161, 167, 177, 196, 199]],
        ];
    }

    /**
     * Assert the selected rule's exact finding lines for each precision fixture.
     *
     * @param string    $fixture       - Fixture filename under tests/Fixtures/Security to analyse.
     * @param string    $ruleId        - Rule whose findings are pinned.
     * @param list<int> $expectedLines - Exact finding lines the fixture must produce.
     *
     * @return void - Asserts the exact sorted finding lines for the selected rule.
     */
    #[DataProvider('precisionFixtureCases')]
    public function testPrecisionFixturesFlagExactLines(string $fixture, string $ruleId, array $expectedLines): void
    {
        $findings = $this->findingsForRule($this->parseFixture($fixture), $ruleId);

        self::assertSame($expectedLines, self::findingLines($findings));
    }

    /**
     * Verify XML loader calls on typed and assigned properties of the current object keep the receiver gate precise.
     *
     * @return void - Asserts findings for typed, assigned, and promoted XML properties only.
     */
    public function testXmlPropertyReceiversUseTypedAndAssignedEvidence(): void
    {
        $findings = $this->findingsForRule($this->xmlPropertyReceiverUnit(), UnsafeXmlLoadingRule::ID);

        self::assertSame([13, 14, 18, 26], self::findingLines($findings));
    }

    /**
     * Assert SQL-concatenation and disabled-SSL finding counts.
     *
     * @return void - Asserts three findings for each of the two selected rules.
     */
    public function testBoundarySecurityPatternsDetected(): void
    {
        $findings = $this->analyse('includes-sql-ssl.php');

        self::assertRuleCount(SqlConcatenationRule::ID, 3, $findings);
        self::assertRuleCount(DisabledSslVerificationRule::ID, 3, $findings);
    }

    /**
     * Provide security-rule option overrides and the expected finding lines.
     *
     * @return array<string, array{string, string, array<string, bool|list<string>>, list<int>}> - Rule, fixture, options, and expected lines.
     */
    public static function strictOptionOverrideProvider(): array
    {
        return [
            'empty safe receivers re-flag identifier interpolation but keep the keyword gate' => [
                SqlConcatenationRule::ID,
                'sql-concatenation-precision.php',
                ['safeInterpolationReceivers' => []],
                [7, 8, 9, 10, 17, 18, 25],
            ],
        ];
    }

    /**
     * Assert finding lines with the supplied security-rule option overrides.
     *
     * @param string                           $ruleId        - rule whose options are overridden
     * @param string                           $fixture       - precision fixture to analyse
     * @param array<string, bool|list<string>> $options       - full option map for the override
     * @param list<int>                        $expectedLines - lines the rule must flag under the override
     *
     * @return void - Asserts the exact finding lines produced under the option override.
     */
    #[DataProvider('strictOptionOverrideProvider')]
    public function testStrictOptionOverridesReflagRelaxedShapes(string $ruleId, string $fixture, array $options, array $expectedLines): void
    {
        $config = AnalysisConfig::fromRegistry(RuleRegistry::defaults())->withRuleSettings(
            $ruleId,
            new RuleSettings(true, [], $options),
        );

        $findings = $this->findingsForRule($this->parseFixture($fixture), $ruleId, $config);

        self::assertSame($expectedLines, self::findingLines($findings));
    }

    /**
     * Assert counts for process, URL, XML, and archive findings.
     *
     * @return void - Asserts one finding for each of the four selected rules.
     */
    public function testExpandedSecuritySinkPatternsDetected(): void
    {
        $findings = $this->analyse('cumulative-security.php');

        self::assertRuleCount(ProcessCommandConstructionRule::ID, 1, $findings);
        self::assertRuleCount(RequestControlledUrlRule::ID, 1, $findings);
        self::assertRuleCount(UnsafeXmlLoadingRule::ID, 1, $findings);
        self::assertRuleCount(UnsafeArchiveExtractionRule::ID, 1, $findings);
    }

    /**
     * Assert that safe-patterns.php produces no security findings.
     *
     * @return void - Asserts an empty list after filtering findings to security rule ids.
     */
    public function testSafeWrappersAndLiteralPatternsAreNotFlagged(): void
    {
        $findings = $this->analyse('safe-patterns.php');

        $securityFindings = array_values(array_filter(
                                             $findings,
                                             static fn(Finding $finding): bool => str_starts_with($finding->ruleId, 'security.'),
                                         ));

        self::assertSame([], $securityFindings);
    }

    /**
     * Verify unserialize() with allowed_classes set to false is not reported.
     *
     * @return void - Asserts that the guarded call yields no unsafe-unserialize findings.
     */
    public function testUnserializeAllowedClassesFalseIsNotFlagged(): void
    {
        $findings = $this->findingsForRule($this->safeUnserializeUnit(), UnsafeUnserializeRule::ID);

        self::assertSame([], $findings);
    }

    /**
     * Assert that the cumulative fixture reports the listed security rules without duplicate fingerprints.
     *
     * @return void - Asserts presence of the listed rule ids and uniqueness of finding fingerprints.
     */
    public function testCumulativeSecurityFixtureCoversEverySecurityRuleWithoutDuplicateFindings(): void
    {
        $findings = array_values(array_filter(
                                     $this->analyse('cumulative-security.php'),
                                     static fn(Finding $finding): bool => str_starts_with($finding->ruleId, 'security.'),
                                 ));

        $ruleIds         = array_map(static fn(Finding $finding): string => $finding->ruleId, $findings);
        $expectedRuleIds = [
            DangerousFunctionCallRule::ID,
            ProcessCommandConstructionRule::ID,
            RequestControlledUrlRule::ID,
            UnsafeXmlLoadingRule::ID,
            UnsafeArchiveExtractionRule::ID,
            UnsafeUnserializeRule::ID,
            SqlConcatenationRule::ID,
            HeaderInjectionRule::ID,
            ErrorSuppressionRule::ID,
            SilentCatchRule::ID,
            ExtractCompactUserInputRule::ID,
            DisabledSslVerificationRule::ID,
        ];

        $missingRuleIds = array_values(array_diff($expectedRuleIds, $ruleIds));

        self::assertSame([], $missingRuleIds);

        $fingerprints = array_map(static fn(Finding $finding): string => $finding->fingerprint(), $findings);
        self::assertCount(count($fingerprints), array_unique($fingerprints));
    }

    /**
     * Assert no dangerous-function findings under the disable-rule fixture configuration.
     *
     * @return void - Asserts a zero count for the dangerous-function rule.
     */
    public function testSecurityRulesRespectConfigDisables(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(__DIR__ . '/../../..'))->load(
            'tests/Fixtures/Config/security-disable-dangerous.yaml',
            $registry,
        );

        $findings = $registry->analyse(
            [$this->dangerousExecutionUnit()],
            new RuleContext(__DIR__ . '/../../..', $config),
        );

        self::assertRuleCount(DangerousFunctionCallRule::ID, 0, $findings);
    }

    /**
     * Assert the expected security finding count for a rule.
     *
     * @param string        $ruleId        - rule id whose findings are counted; all other rules' findings are filtered out first
     * @param int           $expectedCount - exact findings expected for that rule, including zero to assert this analysis produces none
     * @param list<Finding> $findings      - Findings to filter down to the requested rule id.
     *
     * @return void - Asserts that matching findings have the requested count.
     */
    private static function assertRuleCount(string $ruleId, int $expectedCount, array $findings): void
    {
        self::assertCount(
            $expectedCount,
            array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === $ruleId)),
            sprintf('Expected %d findings for %s.', $expectedCount, $ruleId),
        );
    }

    /**
     * List the line numbers a rule flagged, in ascending source order.
     *
     * @param list<Finding> $findings - Findings whose start lines are collected.
     *
     * @return list<int> - sorted start lines of the findings; empty when the rule stayed quiet
     */
    private static function findingLines(array $findings): array
    {
        $lines = array_map(static fn(Finding $finding): int => (int) $finding->line, $findings);
        sort($lines);

        return $lines;
    }

    /**
     * Run the registry with the supplied or fixture configuration and retain one rule's findings.
     *
     * @param AnalysisUnit        $analysisUnit - already-parsed fixture to run the full default registry over
     * @param string              $ruleId       - rule id to retain; findings from every other rule are discarded
     * @param AnalysisConfig|null $config       - Configuration to use; null loads enable-default-off-rules.yaml.
     *
     * @return list<Finding> - only the findings whose ruleId matches $ruleId, re-indexed from zero; empty when that rule never fired
     */
    private function findingsForRule(AnalysisUnit $analysisUnit, string $ruleId, ?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();
        $config ??= (new ConfigLoader(__DIR__ . '/../../..'))->load('tests/Fixtures/Config/enable-default-off-rules.yaml', $registry);
        $findings = $registry->analyse([$analysisUnit], new RuleContext(__DIR__ . '/../../..', $config));


        return array_values(array_filter(
                                $findings,
                                static fn(Finding $finding): bool => $finding->ruleId === $ruleId,
                            ));
    }

    /**
     * Analyse a security fixture with the configuration that enables default-off rules.
     *
     * @param string $fixture - Basename under tests/Fixtures/Security to parse and analyse.
     *
     * @return list<Finding> - Findings from enabled registry rules in canonical report order; empty when none are reported.
     */
    private function analyse(string $fixture): array
    {
        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(__DIR__ . '/../../..'))->load('tests/Fixtures/Config/enable-default-off-rules.yaml', $registry);

        return $registry->analyse(
            [$this->parseFixture($fixture)],
            new RuleContext(__DIR__ . '/../../..', $config),
        );
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - Basename under tests/Fixtures/Security; only a .php suffix selects PHP parsing.
     *
     * @return AnalysisUnit - the parsed fixture, tagged PHP or text by extension so non-PHP fixtures still feed the text-based rules
     */
    private function parseFixture(string $filename): AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Security/' . $filename;
        $type = str_ends_with($filename, '.php') ? SourceFile::TYPE_PHP : SourceFile::TYPE_TEXT;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Security/' . $filename, $type));
    }

    /**
     * Parse the dangerous-execution fixture into an analysis unit.
     *
     * @return AnalysisUnit - unit holding the inline source whose execution-sink calls DangerousFunctionCallRule should flag
     */
    private function dangerousExecutionUnit(): AnalysisUnit
    {
        // Function names are split-concatenated so this test file is not itself flagged as dangerous source.
        return $this->parseSource(
            implode("\n", [
                '<?php',
                '',
                'declare(strict_types=1);',
                '',
                'function runDangerousPatterns(string $command, string $payload): void',
                '{',
                '    ' . 'ex' . 'ec' . '($command);',
                '    ' . 'shell_' . 'exec' . '($command);',
                '    ' . 'pass' . 'thru' . '($command);',
                '    ' . 'sys' . 'tem' . '($command);',
                '    ' . 'po' . 'pen' . '($command, ' . var_export('r', true) . ');',
                '    ' . 'proc_' . 'open' . '($command, [], $pipes);',
                '    ' . 'ev' . 'al' . '($payload);',
                '    ' . 'as' . 'sert' . '(' . var_export('is_string($payload)', true) . ');',
                '',
                '    $call = ' . var_export('system', true) . ';',
                '    $call($command);',
                '}',
            ]) . "\n",
            'tests/Fixtures/Security/inline-execution-calls.php',
        );
    }

    /**
     * Parse the typed-callable fixture into an analysis unit.
     *
     * @return AnalysisUnit - unit whose typed-callable invocations must stay clean, leaving only the literal system() call to flag
     */
    private function typedCallableUnit(): AnalysisUnit
    {

        return $this->parseSource(
            <<<'PHP'
<?php

/**
 * Covers CallableFixture behavior.
 */
final class CallableFixture
{
    public function __construct(private \Closure $stored)
    {
    }

    public function run(callable $mutator, string $command): void
    {
        $mutator($command);
        ($this->stored)($command);
        system($command);
    }

    public function retry(callable $operation, ?callable $beforeRetry): void
    {
        $operation();

        if ($beforeRetry !== null) {
            $beforeRetry();
        }
    }
}
PHP,
            'tests/Fixtures/Security/inline-typed-callable.php',
        );
    }

    /**
     * Parse the local-closure fixture into an analysis unit.
     *
     * @return AnalysisUnit - Fixture with closures, array callables, and a literal-bound system() invocation to distinguish.
     */
    private function localClosureUnit(): AnalysisUnit
    {

        return $this->parseSource(
            <<<'PHP'
<?php

function invokeLocalClosures(string $command): void
{
    $chart = new class {
        public function renderTo(string $target): void
        {
        }

        public function text(string $label): void
        {
        }
    };
    $pager = function (array $page): array {
        return $page;
    };
    $normalizer = static fn (string $value): string => trim($value);
    $chartRenderTo = [$chart, 'renderTo'];
    $chartText = [$chart, 'text'];
    $dynamic = 'system';

    $pager(['page' => 1]);
    $normalizer($command);
    $chartRenderTo('chartContainer');
    $chartText('Chart title');
    $dynamic($command);
}
PHP,
            'tests/Fixtures/Security/inline-local-closures.php',
        );
    }

    /**
     * Parse the callable-collection fixture into an analysis unit.
     *
     * @return AnalysisUnit - Fixture exercising a callable property and closures stored in a property array.
     */
    private function callableCollectionUnit(): AnalysisUnit
    {

        return $this->parseSource(
            <<<'PHP'
<?php

final class CallableCollectionFixture
{
    /** @var array<int, callable(object): void> */
    private array $binders = [];
    private object $statement;
    /** @var callable(): object */
    private $statementFactory;

    public function __construct(callable $statementFactory)
    {
        $this->statementFactory = $statementFactory;
    }

    public function remember(): void
    {
        $this->binders[] = static function (object $statement): void {
            $statement;
        };
    }

    public function replay(): void
    {
        $this->statement = ($this->statementFactory)();

        foreach ($this->binders as $binder) {
            $binder($this->statement);
        }
    }
}
PHP,
            'tests/Fixtures/Security/inline-callable-collection.php',
        );
    }

    /**
     * Parse XML property-receiver shapes into an analysis unit.
     *
     * @return AnalysisUnit - Fixture with unsafe XML-property calls plus safe-option and unknown-receiver controls.
     */
    private function xmlPropertyReceiverUnit(): AnalysisUnit
    {
        return $this->parseSource(
            <<<'PHP'
<?php
final class PropertyXmlReceiverFixture
{
    private DOMDocument $typedDocument;
    private $constructorDocument;
    private $sameScopeDocument;
    private object $builder;
    public function __construct(object $builder)
    {
        $this->constructorDocument = new DOMDocument();
        $this->builder = $builder;
    }
    public function typedPropertyLoad(): void { $this->typedDocument->loadXML($_GET['xml']); }
    public function constructorAssignedPropertyLoad(): void { $this->constructorDocument->loadXML($_GET['xml']); }
    public function sameScopeAssignedPropertyLoad(): void
    {
        $this->sameScopeDocument = new DOMDocument();
        $this->sameScopeDocument->loadXML($_GET['xml']);
    }
    public function safeTypedPropertyLoad(): void { $this->typedDocument->loadXML($_GET['xml'], LIBXML_NONET); }
    public function unknownPropertyReceiver(): void { $this->builder->loadXML($_GET['xml']); }
}
final class PromotedXmlPropertyReceiverFixture
{
    public function __construct(private XMLReader $reader) {}
    public function promotedPropertyOpen(): void { $this->reader->open($_GET['xml']); }
}
PHP,
            'tests/Fixtures/Security/inline-xml-property-receivers.php',
        );
    }

    /**
     * Parse the safe-unserialize fixture into an analysis unit.
     *
     * @return AnalysisUnit - unit whose unserialize call is guarded by allowed_classes => false, so the rule must not flag it
     */
    private function safeUnserializeUnit(): AnalysisUnit
    {

        return $this->parseSource(
            <<<'PHP'
<?php

function restoreUserState(string $serialized): void
{
    unserialize($serialized, ['allowed_classes' => false]);
}
PHP,
            'tests/Fixtures/Security/inline-safe-unserialize.php',
        );
    }

    /**
     * Parse inline source into an analysis unit.
     *
     * @param string $source      - PHP source text; syntax errors fail the test and empty input yields no statements.
     * @param string $displayPath - Display path assigned to the inline fixture in analysis results.
     *
     * @return AnalysisUnit - Parsed inline source with tokens and parent-linked statements under $displayPath.
     */
    private function parseSource(string $source, string $displayPath): AnalysisUnit
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $statements = array_values($parser->parse($source) ?? []);
        } catch (Error $error) {
            self::fail(sprintf('Inline fixture did not parse: %s', $error->getRawMessage()));
        }

        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new ParentConnectingVisitor());
        /** @var list<Stmt> $traversed - Statements connected to parent attributes for rule traversal. */
        $traversed = $nodeTraverser->traverse($statements);

        return new AnalysisUnit(
            new SourceFile(__FILE__, $displayPath),
            $source,
            $traversed,
            array_values($parser->getTokens()),
            [],
        );
    }
}
