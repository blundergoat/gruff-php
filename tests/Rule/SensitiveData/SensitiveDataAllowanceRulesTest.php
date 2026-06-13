<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\SensitiveData;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\SensitiveData\AwsAccessKeyRule;
use GruffPhp\Rules\SensitiveData\HighEntropyStringRule;
use GruffPhp\Rules\SensitiveData\JwtTokenRule;
use GruffPhp\Rules\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers sensitive-data allowance precision: real-secret and real-PII counter-fixtures must keep
 * flagging while identifier/slug literals and synthetic PII shapes pass the built-in allowances.
 */
final class SensitiveDataAllowanceRulesTest extends TestCase
{
    /** Project root used to resolve fixture paths. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /** Real-secret counter-fixture: every shape in it must always flag. */
    private const SECRET_FIXTURE = 'tests/Fixtures/SensitiveData/secret-counter-shapes.php';

    /** Identifier/slug literals the entropy gate must not mistake for secrets. */
    private const IDENTIFIER_FIXTURE = 'tests/Fixtures/SensitiveData/identifier-slug-literals.php';

    /** Realistic PII counter-fixture: every value in it must always flag. */
    private const PII_REALISTIC_FIXTURE = 'tests/Fixtures/SensitiveData/pii-realistic.php';

    /** Synthetic PII shapes covered by the reserved-domain and marker-word allowances. */
    private const PII_SYNTHETIC_FIXTURE = 'tests/Fixtures/SensitiveData/pii-synthetic-allowed.php';

    /** Fixture line of the dot-joined base64url token that must never read as a dotted identifier. */
    private const DOT_JOINED_TOKEN_LINE = 10;

    /**
     * List fixture/rule pairs with the exact lines each rule must flag.
     *
     * @return array<string, array{0: string, 1: string, 2: list<int>}> - dataset name to fixture path, rule id, and the exact ascending lines the
     *                       rule must flag (empty when the fixture must stay quiet)
     */
    public static function fixtureLineExpectations(): array
    {
        return [
            'base64, hex, npm-integrity, dot-joined, and prefixed-key tokens flag as high entropy' => [
                self::SECRET_FIXTURE,
                HighEntropyStringRule::ID,
                [5, 6, 7, self::DOT_JOINED_TOKEN_LINE, 11, 12, 13, 14],
            ],
            'JWT literal flags under the dedicated JWT rule'                         => [self::SECRET_FIXTURE, JwtTokenRule::ID, [9]],
            'AWS key id flags under the dedicated AWS rule'                          => [self::SECRET_FIXTURE, AwsAccessKeyRule::ID, [8]],
            'realistic email, address, and phone PII flags'                          => [self::PII_REALISTIC_FIXTURE, PiiTestFixtureRule::ID, [5, 6, 7]],
            'identifier and slug literals pass the entropy gate'                     => [self::IDENTIFIER_FIXTURE, HighEntropyStringRule::ID, []],
            'reserved-domain emails and marker addresses pass the PII gate'          => [self::PII_SYNTHETIC_FIXTURE, PiiTestFixtureRule::ID, []],
        ];
    }

    /**
     * Verify each fixture flags exactly the expected lines for the rule under test.
     *
     * @param string    $fixturePath - Project-relative fixture path to analyse.
     * @param string    $ruleId - Rule whose findings are isolated.
     * @param list<int> $expectedLines - Lines the rule must flag, in ascending order.
     *
     * @return void
     */
    #[DataProvider('fixtureLineExpectations')]
    public function testFixtureLinesFlagExactly(string $fixturePath, string $ruleId, array $expectedLines): void
    {
        self::assertSame($expectedLines, $this->flaggedLines($fixturePath, $ruleId));
    }

    /**
     * Verify the dotted-identifier allowance never swallows dot-joined token material.
     *
     * @return void
     */
    public function testDotJoinedTokenIsNotExemptAsDottedIdentifier(): void
    {
        $flaggedLines = $this->flaggedLines(self::SECRET_FIXTURE, HighEntropyStringRule::ID);

        self::assertContains(
            self::DOT_JOINED_TOKEN_LINE,
            $flaggedLines,
            'A dot-joined base64url token must keep flagging: its segments are not word-shaped, so the dotted-identifier allowance must not cover it.',
        );
    }

    /**
     * Analyse a fixture and return the sorted lines one rule flagged.
     *
     * @param string $displayPath - Project-relative fixture path.
     * @param string $ruleId - Rule identifier to filter for.
     *
     * @return list<int> - ascending 1-based lines the rule flagged; empty when the rule stayed quiet
     */
    private function flaggedLines(string $displayPath, string $ruleId): array
    {
        $unit     = (new PhpFileParser())->parse(new SourceFile(self::PROJECT_ROOT . '/' . $displayPath, $displayPath));
        $registry = RuleRegistry::defaults();
        $findings = $registry->analyse([$unit], new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)));

        $flaggedLines = array_values(array_map(
            static fn(Finding $finding): int => (int) $finding->line,
            array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === $ruleId),
        ));
        sort($flaggedLines);

        return $flaggedLines;
    }
}
