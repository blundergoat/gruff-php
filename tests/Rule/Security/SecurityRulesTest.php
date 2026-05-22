<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Security\DangerousFunctionCallRule;
use GruffPhp\Rule\Security\DisabledSslVerificationRule;
use GruffPhp\Rule\Security\ErrorSuppressionRule;
use GruffPhp\Rule\Security\ExtractCompactUserInputRule;
use GruffPhp\Rule\Security\GithubActionsRiskyWorkflowRule;
use GruffPhp\Rule\Security\HeaderInjectionRule;
use GruffPhp\Rule\Security\InsecureRandomRule;
use GruffPhp\Rule\Security\PathTraversalFileAccessRule;
use GruffPhp\Rule\Security\ProcessCommandConstructionRule;
use GruffPhp\Rule\Security\RequestControlledUrlRule;
use GruffPhp\Rule\Security\SensitiveDataLoggingRule;
use GruffPhp\Rule\Security\SilentCatchRule;
use GruffPhp\Rule\Security\SqlConcatenationRule;
use GruffPhp\Rule\Security\UnsafeArchiveExtractionRule;
use GruffPhp\Rule\Security\UnsafeXmlLoadingRule;
use GruffPhp\Rule\Security\UnsafeUnserializeRule;
use GruffPhp\Rule\Security\VariableIncludeRule;
use GruffPhp\Rule\Security\WeakCryptoRule;
use GruffPhp\Source\SourceFile;
use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers SecurityRulesTest behavior.
 */
final class SecurityRulesTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void No return value.
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify dangerous execution patterns detected.
     *
     * @return void No return value.
     */
    public function testDangerousExecutionPatternsDetected(): void
    {
        $findings = $this->findingsForRule($this->dangerousExecutionUnit(), DangerousFunctionCallRule::ID);

        self::assertCount(9, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);

        $functions = array_map(static fn (Finding $finding): mixed => $finding->metadata['function'] ?? null, $findings);
        self::assertContains('exec', $functions);
        self::assertContains('shell_exec', $functions);
        self::assertContains('eval', $functions);
        self::assertContains('assert string evaluation', $functions);
        self::assertContains('dynamic function call', $functions);
    }

    /**
     * Verify typed callable invocations are not dangerous dynamic calls.
     *
     * @return void No return value.
     */
    public function testTypedCallableInvocationsAreNotDangerousDynamicCalls(): void
    {
        $findings  = $this->findingsForRule($this->typedCallableUnit(), DangerousFunctionCallRule::ID);
        $functions = array_map(static fn (Finding $finding): mixed => $finding->metadata['function'] ?? null, $findings);

        self::assertNotContains('dynamic function call', $functions);
        self::assertContains('system', $functions);
    }

    /**
     * Verify local closure invocations are not treated like arbitrary dynamic calls.
     *
     * @return void No return value.
     */
    public function testLocalClosureInvocationsAreNotDangerousDynamicCalls(): void
    {
        $findings  = $this->findingsForRule($this->localClosureUnit(), DangerousFunctionCallRule::ID);
        $functions = array_map(static fn (Finding $finding): mixed => $finding->metadata['function'] ?? null, $findings);

        self::assertSame(['dynamic function call'], $functions);
    }

    /**
     * Verify callable collections are not treated like arbitrary dynamic calls.
     *
     * @return void No return value.
     */
    public function testCallableCollectionInvocationsAreNotDangerousDynamicCalls(): void
    {
        $findings = $this->findingsForRule($this->callableCollectionUnit(), DangerousFunctionCallRule::ID);

        self::assertSame([], $findings);
    }

    /**
     * Verify static logger text that names sensitive concepts is not treated as leaked data.
     *
     * @return void No return value.
     */
    public function testStaticSensitiveLoggerMessagesAreNotFlagged(): void
    {
        $findings = $this->findingsForRule($this->staticLoggerMessageUnit(), SensitiveDataLoggingRule::ID);

        self::assertSame([], $findings);
    }

    /**
     * Verify runtime sensitive log values still produce findings.
     *
     * @return void No return value.
     */
    public function testSensitiveLoggerRuntimeValuesStillFlagged(): void
    {
        $findings = $this->findingsForRule($this->runtimeLoggerValueUnit(), SensitiveDataLoggingRule::ID);

        self::assertCount(2, $findings);
    }

    /**
     * Verify request data security heuristics detected.
     *
     * @return void No return value.
     */
    public function testRequestDataSecurityHeuristicsDetected(): void
    {
        $findings = $this->analyse('data-flow-heuristics.php');

        self::assertRuleCount(UnsafeUnserializeRule::ID, 1, $findings);
        self::assertRuleCount(HeaderInjectionRule::ID, 2, $findings);
        self::assertRuleCount(ExtractCompactUserInputRule::ID, 3, $findings);
        self::assertRuleCount(WeakCryptoRule::ID, 3, $findings);
        self::assertRuleCount(InsecureRandomRule::ID, 3, $findings);
        self::assertRuleCount(ErrorSuppressionRule::ID, 1, $findings);
        self::assertRuleCount(SilentCatchRule::ID, 1, $findings);
    }

    /**
     * Verify boundary security patterns detected.
     *
     * @return void No return value.
     */
    public function testBoundarySecurityPatternsDetected(): void
    {
        $findings = $this->analyse('includes-sql-ssl.php');

        self::assertRuleCount(VariableIncludeRule::ID, 2, $findings);
        self::assertRuleCount(SqlConcatenationRule::ID, 3, $findings);
        self::assertRuleCount(DisabledSslVerificationRule::ID, 3, $findings);
    }

    /**
     * Verify deterministic bootstrap include paths are not treated as variable includes.
     *
     * @return void No return value.
     */
    public function testFixedBootstrapIncludePathsAreNotVariableIncludes(): void
    {
        $findings = $this->findingsForRule($this->fixedIncludeUnit(), VariableIncludeRule::ID);

        self::assertCount(1, $findings);
        self::assertSame(9, $findings[0]->line);
    }

    /**
     * Verify expanded security sink patterns detected.
     *
     * @return void No return value.
     */
    public function testExpandedSecuritySinkPatternsDetected(): void
    {
        $findings = $this->analyse('cumulative-security.php');

        self::assertRuleCount(ProcessCommandConstructionRule::ID, 1, $findings);
        self::assertRuleCount(PathTraversalFileAccessRule::ID, 1, $findings);
        self::assertRuleCount(RequestControlledUrlRule::ID, 1, $findings);
        self::assertRuleCount(UnsafeXmlLoadingRule::ID, 1, $findings);
        self::assertRuleCount(UnsafeArchiveExtractionRule::ID, 1, $findings);
        self::assertRuleCount(SensitiveDataLoggingRule::ID, 1, $findings);
    }

    /**
     * Verify risky GitHub Actions workflow patterns detected.
     *
     * @return void No return value.
     */
    public function testGithubActionsWorkflowRisksDetected(): void
    {
        $findings = $this->analyse('.github/workflows/risky-workflow.yml');

        self::assertRuleCount(GithubActionsRiskyWorkflowRule::ID, 6, $findings);
    }

    /**
     * Verify safe wrappers and literal patterns are not flagged.
     *
     * @return void No return value.
     */
    public function testSafeWrappersAndLiteralPatternsAreNotFlagged(): void
    {
        $findings = [
            ...$this->analyse('safe-patterns.php'),
            ...$this->analyse('.github/workflows/safe-workflow.yml'),
        ];

        $securityFindings = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => str_starts_with($finding->ruleId, 'security.'),
        ));

        self::assertSame([], $securityFindings);
    }

    /**
     * Verify unserialize with object hydration disabled is not reported.
     *
     * @return void No return value.
     */
    public function testUnserializeAllowedClassesFalseIsNotFlagged(): void
    {
        $findings = $this->findingsForRule($this->safeUnserializeUnit(), UnsafeUnserializeRule::ID);

        self::assertSame([], $findings);
    }

    /**
     * Verify cumulative security fixture covers every security rule without duplicate findings.
     *
     * @return void No return value.
     */
    public function testCumulativeSecurityFixtureCoversEverySecurityRuleWithoutDuplicateFindings(): void
    {
        $findings = array_values(array_filter(
            [
                ...$this->analyse('cumulative-security.php'),
                ...$this->analyse('.github/workflows/cumulative-workflow.yml'),
            ],
            static fn (Finding $finding): bool => str_starts_with($finding->ruleId, 'security.'),
        ));

        $ruleIds         = array_map(static fn (Finding $finding): string => $finding->ruleId, $findings);
        $expectedRuleIds = [
            DangerousFunctionCallRule::ID,
            ProcessCommandConstructionRule::ID,
            PathTraversalFileAccessRule::ID,
            RequestControlledUrlRule::ID,
            UnsafeXmlLoadingRule::ID,
            UnsafeArchiveExtractionRule::ID,
            SensitiveDataLoggingRule::ID,
            GithubActionsRiskyWorkflowRule::ID,
            UnsafeUnserializeRule::ID,
            WeakCryptoRule::ID,
            VariableIncludeRule::ID,
            SqlConcatenationRule::ID,
            HeaderInjectionRule::ID,
            ErrorSuppressionRule::ID,
            SilentCatchRule::ID,
            ExtractCompactUserInputRule::ID,
            InsecureRandomRule::ID,
            DisabledSslVerificationRule::ID,
        ];

        $missingRuleIds = array_values(array_diff($expectedRuleIds, $ruleIds));

        self::assertSame([], $missingRuleIds);

        $fingerprints = array_map(static fn (Finding $finding): string => $finding->fingerprint(), $findings);
        self::assertCount(count($fingerprints), array_unique($fingerprints));
    }

    /**
     * Verify security rules respect config disables.
     *
     * @return void No return value.
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
     * @param list<Finding> $findings
     * @return void No return value.
     */
    private static function assertRuleCount(string $ruleId, int $expectedCount, array $findings): void
    {
        self::assertCount(
            $expectedCount,
            array_values(array_filter($findings, static fn (Finding $finding): bool => $finding->ruleId === $ruleId)),
            sprintf('Expected %d findings for %s.', $expectedCount, $ruleId),
        );
    }

    /**
     * @return list<Finding>
     */
    private function findingsForRule(AnalysisUnit $analysisUnit, string $ruleId): array
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$analysisUnit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->ruleId === $ruleId,
        ));
    }

    /**
     * @return list<Finding>
     */
    private function analyse(string $fixture): array
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);

        return $registry->analyse(
            [$this->parseFixture($fixture)],
            new RuleContext(__DIR__ . '/../../..', $config),
        );
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     * @return AnalysisUnit Fixture value.
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
     * @return AnalysisUnit Fixture value.
     */
    private function dangerousExecutionUnit(): AnalysisUnit
    {
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
     * @return AnalysisUnit Fixture value.
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
     * @return AnalysisUnit Fixture value.
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
     * @return AnalysisUnit Fixture value.
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
     * Parse the static logger message fixture into an analysis unit.
     *
     * @return AnalysisUnit Fixture value.
     */
    private function staticLoggerMessageUnit(): AnalysisUnit
    {
        return $this->parseSource(
            <<<'PHP'
<?php

final class StaticLoggerMessageFixture
{
    public function record(object $logger): void
    {
        $logger->info('flushing PreferenceAuthUser and AuthCredential', ['method' => __METHOD__, 'line' => __LINE__]);
        $logger->info('user already has a valid AuthCredentialToken', ['method' => __METHOD__, 'line' => __LINE__]);
        $logger->warning('token refresh skipped for a static branch');
    }
}
PHP,
            'tests/Fixtures/Security/inline-static-logger-message.php',
        );
    }

    /**
     * Parse the runtime logger value fixture into an analysis unit.
     *
     * @return AnalysisUnit Fixture value.
     */
    private function runtimeLoggerValueUnit(): AnalysisUnit
    {
        return $this->parseSource(
            <<<'PHP'
<?php

final class RuntimeLoggerValueFixture
{
    public function record(object $logger, string $password, string $token): void
    {
        $logger->warning($password);
        $logger->info('token refresh failed', ['token' => $token]);
    }
}
PHP,
            'tests/Fixtures/Security/inline-runtime-logger-value.php',
        );
    }

    /**
     * Parse the fixed-include fixture into an analysis unit.
     *
     * @return AnalysisUnit Fixture value.
     */
    private function fixedIncludeUnit(): AnalysisUnit
    {
        return $this->parseSource(
            <<<'PHP'
<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/../bin/ShutdownFunctions.php';
if (is_array($env = @include dirname(__DIR__) . '/.env.local.php')) {
    $env;
}

require_once __DIR__ . '/' . ($_GET['page'] ?? 'home.php');
PHP,
            'tests/Fixtures/Security/inline-fixed-includes.php',
        );
    }

    /**
     * Parse the safe-unserialize fixture into an analysis unit.
     *
     * @return AnalysisUnit Fixture value.
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
     * @param string $source      Source directory.
     * @param string $displayPath Fixture display path.
     * @return AnalysisUnit Fixture value.
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
        /** @var list<Stmt> $traversed Statements connected to parent attributes for rule traversal. */
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
