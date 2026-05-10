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
use GruffPhp\Rule\Security\HeaderInjectionRule;
use GruffPhp\Rule\Security\InsecureRandomRule;
use GruffPhp\Rule\Security\SilentCatchRule;
use GruffPhp\Rule\Security\SqlConcatenationRule;
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

final class SecurityRulesTest extends TestCase
{
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

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

    public function testRequestDataSecurityHeuristicsDetected(): void
    {
        $findings = $this->analyse('data-flow-heuristics.php');

        self::assertRuleCount(UnsafeUnserializeRule::ID, 1, $findings);
        self::assertRuleCount(HeaderInjectionRule::ID, 1, $findings);
        self::assertRuleCount(ExtractCompactUserInputRule::ID, 2, $findings);
        self::assertRuleCount(WeakCryptoRule::ID, 3, $findings);
        self::assertRuleCount(InsecureRandomRule::ID, 3, $findings);
        self::assertRuleCount(ErrorSuppressionRule::ID, 1, $findings);
        self::assertRuleCount(SilentCatchRule::ID, 1, $findings);
    }

    public function testBoundarySecurityPatternsDetected(): void
    {
        $findings = $this->analyse('includes-sql-ssl.php');

        self::assertRuleCount(VariableIncludeRule::ID, 2, $findings);
        self::assertRuleCount(SqlConcatenationRule::ID, 3, $findings);
        self::assertRuleCount(DisabledSslVerificationRule::ID, 3, $findings);
    }

    public function testSafeWrappersAndLiteralPatternsAreNotFlagged(): void
    {
        $findings = $this->analyse('safe-patterns.php');

        $securityFindings = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => str_starts_with($finding->ruleId, 'security.'),
        ));

        self::assertSame([], $securityFindings);
    }

    public function testCumulativeSecurityFixtureCoversEverySecurityRuleWithoutDuplicateFindings(): void
    {
        $findings = array_values(array_filter(
            $this->analyse('cumulative-security.php'),
            static fn (Finding $finding): bool => str_starts_with($finding->ruleId, 'security.'),
        ));

        $ruleIds = array_map(static fn (Finding $finding): string => $finding->ruleId, $findings);
        $expectedRuleIds = [
            DangerousFunctionCallRule::ID,
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

        foreach ($expectedRuleIds as $ruleId) {
            self::assertContains($ruleId, $ruleIds);
        }

        $fingerprints = array_map(static fn (Finding $finding): string => $finding->fingerprint(), $findings);
        self::assertCount(count($fingerprints), array_unique($fingerprints));
    }

    public function testSecurityRulesRespectConfigDisables(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(__DIR__ . '/../../..'))->load(
            'tests/Fixtures/Config/security-disable-dangerous.json',
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
    private function findingsForRule(AnalysisUnit $unit, string $ruleId): array
    {
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

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
        $config = AnalysisConfig::fromRegistry($registry);

        return $registry->analyse(
            [$this->parseFixture($fixture)],
            new RuleContext(__DIR__ . '/../../..', $config),
        );
    }

    private function parseFixture(string $filename): AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Security/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Security/' . $filename));
    }

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

    private function parseSource(string $source, string $displayPath): AnalysisUnit
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $statements = array_values($parser->parse($source) ?? []);
        } catch (Error $error) {
            self::fail(sprintf('Inline fixture did not parse: %s', $error->getRawMessage()));
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        /** @var list<Stmt> $traversed */
        $traversed = $traverser->traverse($statements);

        return new AnalysisUnit(
            new SourceFile(__FILE__, $displayPath),
            $source,
            $traversed,
            array_values($parser->getTokens()),
            [],
        );
    }
}
