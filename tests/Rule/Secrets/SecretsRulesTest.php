<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Secrets;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Secrets\ApiKeyPatternRule;
use GruffPhp\Rule\Secrets\AwsAccessKeyRule;
use GruffPhp\Rule\Secrets\DatabaseUrlPasswordRule;
use GruffPhp\Rule\Secrets\HardcodedEnvValueRule;
use GruffPhp\Rule\Secrets\HighEntropyStringRule;
use GruffPhp\Rule\Secrets\JwtTokenRule;
use GruffPhp\Rule\Secrets\PhiPatternRule;
use GruffPhp\Rule\Secrets\PiiTestFixtureRule;
use GruffPhp\Rule\Secrets\PrivateKeyRule;
use GruffPhp\Source\SourceDiscovery;
use GruffPhp\Source\SourceFile;
use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SecretsRulesTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    public function testCredentialPatternsAreDetectedWithRedactedPreviews(): void
    {
        $findings = $this->analysePath('tests/Fixtures/M11/Secrets/synthetic-secrets.php');

        self::assertRuleCount(AwsAccessKeyRule::ID, 1, $findings);
        self::assertRuleCount(ApiKeyPatternRule::ID, 5, $findings);
        self::assertRuleCount(JwtTokenRule::ID, 1, $findings);
        self::assertRuleCount(DatabaseUrlPasswordRule::ID, 1, $findings);
        self::assertRuleCount(HardcodedEnvValueRule::ID, 1, $findings);
        self::assertRuleCount(HighEntropyStringRule::ID, 1, $findings);
        self::assertRuleCount(PrivateKeyRule::ID, 1, $findings);

        foreach ($this->secretValues() as $secret) {
            foreach ($findings as $finding) {
                self::assertStringNotContainsString($secret, $finding->message);
                self::assertStringNotContainsString($secret, json_encode($finding->metadata, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testConfigLikeFilesAreDiscoveredAndScannedAsText(): void
    {
        $discovery = new SourceDiscovery(self::PROJECT_ROOT);
        $result = $discovery->discover(['tests/Fixtures/M11/Secrets/config-secrets.json']);

        self::assertCount(1, $result->files);
        self::assertSame(SourceFile::TYPE_TEXT, $result->files[0]->type);

        $unit = (new PhpFileParser())->parse($result->files[0]);

        self::assertFalse($unit->hasParseErrors());
        self::assertSame([], $unit->statements);

        $findings = $this->analyseUnits([$unit]);

        self::assertRuleCount(DatabaseUrlPasswordRule::ID, 1, $findings);
        self::assertRuleCount(HardcodedEnvValueRule::ID, 1, $findings);
        self::assertRuleCount(HighEntropyStringRule::ID, 1, $findings);
    }

    public function testPhiAndPiiProfilesAreDetectedInFixtureData(): void
    {
        $findings = $this->analysePath('tests/Fixtures/M11/Secrets/profile-data.json');

        self::assertRuleCount(PhiPatternRule::ID, 5, $findings);
        self::assertRuleCount(PiiTestFixtureRule::ID, 3, $findings);
    }

    public function testAllowedDummyValuesAreNotFlagged(): void
    {
        $findings = array_values(array_filter(
            $this->analysePath('tests/Fixtures/M11/Secrets/safe-dummy-values.php'),
            static fn (Finding $finding): bool => str_starts_with($finding->ruleId, 'secrets.'),
        ));

        self::assertSame([], $findings);
    }

    public function testSecretRulesRespectDetectorSelectionConfig(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/M11/Config/disable-high-entropy.json',
            $registry,
        );
        $findings = $this->analyseUnits(
            [$this->unitForPath('tests/Fixtures/M11/Secrets/synthetic-secrets.php')],
            $config,
        );

        self::assertRuleCount(HighEntropyStringRule::ID, 0, $findings);
        self::assertRuleCount(AwsAccessKeyRule::ID, 1, $findings);
    }

    /**
     * @throws JsonException
     */
    public function testCliTextAndJsonReportsDoNotLeakFullSecrets(): void
    {
        $text = $this->runGruff([
            'analyse',
            'tests/Fixtures/M11/Secrets/synthetic-secrets.php',
            'tests/Fixtures/M11/Secrets/config-secrets.json',
            '--fail-on',
            'none',
        ]);
        $json = $this->runGruff([
            'analyse',
            'tests/Fixtures/M11/Secrets/synthetic-secrets.php',
            'tests/Fixtures/M11/Secrets/config-secrets.json',
            '--format',
            'json',
            '--fail-on',
            'none',
        ]);

        json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        foreach ($this->secretValues() as $secret) {
            self::assertStringNotContainsString($secret, $text);
            self::assertStringNotContainsString($secret, $json);
        }

        self::assertStringContainsString('redacted', $text);
        self::assertStringContainsString('redacted', $json);
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
    private function analysePath(string $path): array
    {
        return $this->analyseUnits([$this->unitForPath($path)]);
    }

    /**
     * @param list<AnalysisUnit> $units
     * @return list<Finding>
     */
    private function analyseUnits(array $units, ?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();

        return $registry->analyse(
            $units,
            new RuleContext(self::PROJECT_ROOT, $config ?? AnalysisConfig::fromRegistry($registry)),
        );
    }

    private function unitForPath(string $path): AnalysisUnit
    {
        $absolutePath = self::PROJECT_ROOT . '/' . $path;
        $type = str_ends_with($path, '.php') ? SourceFile::TYPE_PHP : SourceFile::TYPE_TEXT;

        return (new PhpFileParser())->parse(new SourceFile($absolutePath, $path, $type));
    }

    /**
     * @param list<string> $arguments
     */
    private function runGruff(array $arguments): string
    {
        $process = new Process(array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff'], $arguments), self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        return $process->getOutput();
    }

    /**
     * @return list<string>
     */
    private function secretValues(): array
    {
        return [
            'AKIA' . 'Z9Y8X7W6V5U4T3R2',
            'sk_live_' . '51N7uQbP0JZ6rT9vL3mK8sX2y',
            'ghp_' . 'aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789',
            'sk-proj-' . 'uQ7vR2mN5xP8zL1kC4bH9sT6wY3aD0fG',
            'sk-ant-api03-' . 'uQ7vR2mN5xP8zL1kC4bH9sT6wY3aD0fG',
            'xoxb-' . '123456789012-987654321098-AbCdEfGhIjKlMnOpQrSt',
            'eyJhbGciOiJIUzI1NiJ9.' . 'eyJzdWIiOiIxMjM0NTY3ODkwIn0.' . 'sflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c',
            'mysql://appuser:' . 'rN7pQ4sV9xY2zA5b' . '@db.internal/app',
            'postgres://reporter:' . 'qR8vT3mK6pL9xS2n' . '@db.internal/reporting',
            'DB_PASSWORD=' . 'rN7pQ4sV9xY2zA5b',
            'API_TOKEN=' . 'qR8vT3mK6pL9xS2n',
            'M7qP2vL9xZ4aB8nC3dF6gH1jK5mN0rS2tV9wY4zQ',
            'N8pQ3rT6uW9xY2zA5bC8dF1gH4jK7mP0sV3wX6yZ',
        ];
    }
}
