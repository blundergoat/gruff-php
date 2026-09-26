<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\SensitiveExclusion;
use GruffPhp\Rules\RuleRegistry;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the `sensitiveExclusions:` config block a user writes to accept a reviewed sensitive-data
 * finding.
 *
 * The accepted shapes prove a narrow scope survives loading with its rationale intact; the rejected
 * shapes are the numbered rejections of the family suppression contract, each of which must stop the
 * user's run with a message naming the entry index and the offending key rather than quietly hiding
 * findings. Users exercise these paths whenever a project config accepts a synthetic credential.
 */
final class SensitiveExclusionConfigParserTest extends ConfigLoaderTestCase
{
    /**
     * Valid entry every rejection row starts from, so a row states only the field under test.
     *
     * @var array<string, string>
     */
    private const VALID_ENTRY = [
        'rule'   => 'sensitive-data.aws-access-key',
        'path'   => 'corpus/AwsSample.php',
        'reason' => 'Synthetic fixture.',
    ];

    /**
     * Verifies a reviewed entry loads with its rule, path, and rationale intact and no symbol.
     *
     * @return void
     * @throws JsonException
     */
    public function testLoadsFileScopedExclusion(): void
    {
        $exclusions = $this->loadExclusions(self::configJson([[
            'rule'   => 'sensitive-data.aws-access-key',
            'path'   => 'corpus/AwsSample.php',
            'reason' => 'Synthetic key; not a live credential.',
        ]]));

        self::assertCount(1, $exclusions);
        self::assertSame('sensitive-data.aws-access-key', $exclusions[0]->ruleId);
        self::assertSame('corpus/AwsSample.php', $exclusions[0]->path);
        self::assertNull($exclusions[0]->symbol);
        self::assertSame('Synthetic key; not a live credential.', $exclusions[0]->reason);
    }

    /**
     * Verifies an optional symbol is accepted and narrows the entry below file scope.
     *
     * @return void
     * @throws JsonException
     */
    public function testLoadsSymbolScopedExclusion(): void
    {
        $exclusions = $this->loadExclusions(self::configJson([[
            'rule'   => 'sensitive-data.jwt-token',
            'path'   => 'corpus/JwtSample.php',
            'symbol' => 'SyntheticFixtureSymbol',
            'reason' => 'Narrowed while the fixture is refactored.',
        ]]));

        self::assertCount(1, $exclusions);
        self::assertSame('SyntheticFixtureSymbol', $exclusions[0]->symbol);
    }

    /**
     * Verifies two entries with different scopes both survive loading, in configuration order, so
     * each owns its own audit index.
     *
     * @return void
     * @throws JsonException
     */
    public function testLoadsTwoIndependentExclusions(): void
    {
        $exclusions = $this->loadExclusions(self::configJson([
            ['rule' => 'sensitive-data.aws-access-key', 'path' => 'corpus/AwsSample.php', 'reason' => 'Synthetic AWS key.'],
            ['rule' => 'sensitive-data.jwt-token', 'path' => 'corpus/JwtSample.php', 'reason' => 'Synthetic JWT.'],
        ]));

        self::assertCount(2, $exclusions);
        self::assertSame('sensitive-data.aws-access-key', $exclusions[0]->ruleId);
        self::assertSame('sensitive-data.jwt-token', $exclusions[1]->ruleId);
    }

    /**
     * Verifies an omitted block leaves the run with nothing suppressed, so the section stays opt-in.
     *
     * @return void
     */
    public function testOmittedBlockSuppressesNothing(): void
    {
        self::assertSame([], $this->loadExclusions('{"minimumPhpVersion":8.3}'));
    }

    /**
     * Verifies each rejected shape stops the user's run with its exact actionable message, and that
     * the message names the term the cross-port acceptance cases require a reader to see.
     *
     * @param string $configJson - Inline JSON config carrying the rejected `sensitiveExclusions` shape.
     * @param string $expectedMessage - Exact message the user must receive for this case.
     * @param string $requiredMention - Term the family case file requires the diagnostic to name.
     *
     * @return void
     */
    #[DataProvider('rejectedExclusionProvider')]
    public function testRejectsInvalidExclusion(string $configJson, string $expectedMessage, string $requiredMention): void
    {
        self::assertStringContainsString($requiredMention, $expectedMessage);

        $path = $this->writeTempConfig($configJson);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($expectedMessage);

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * Provides one row per numbered rejection in the family suppression contract, so a failed row
     * identifies which guarantee broke.
     *
     * @return array<string, array{string, string, string}> - config JSON, exact expected message, and the term the diagnostic must mention.
     * @throws JsonException
     */
    public static function rejectedExclusionProvider(): array
    {
        return [
            ...self::ruleRejections(),
            ...self::pathRejections(),
            ...self::entryRejections(),
        ];
    }

    /**
     * Rejections 1 to 4: `rule` must name exactly one known sensitive-data rule id.
     *
     * @return array<string, array{string, string, string}> - config JSON, exact expected message, and the term the diagnostic must mention.
     * @throws JsonException
     */
    private static function ruleRejections(): array
    {
        $patternMessage = 'Config key "sensitiveExclusions[0].rule" must name exactly one rule id; wildcards, globs, and regular expressions are not accepted.';

        return [
            'rejection 1: wildcard rule'      => [self::entryJson(['rule' => '*']), $patternMessage, 'rule'],
            'rejection 1: glob selector rule' => [self::entryJson(['rule' => 'sensitive-data.*']), $patternMessage, 'rule'],
            'rejection 1: missing rule'       => [
                self::configJson([['path' => 'corpus/AwsSample.php', 'reason' => 'Synthetic fixture.']]),
                'Config key "sensitiveExclusions[0].rule" must be a non-empty string.',
                'rule',
            ],
            'rejection 2: pillar selector'    => [
                self::entryJson(['rule' => 'sensitive-data']),
                'Config key "sensitiveExclusions[0].rule" names the "sensitive-data" pillar, not a single rule id.',
                'rule',
            ],
            'rejection 3: unknown rule'       => [
                self::entryJson(['rule' => 'sensitive-data.not-a-real-rule']),
                'Config key "sensitiveExclusions[0].rule" is not a known rule id: "sensitive-data.not-a-real-rule".',
                'rule',
            ],
            'rejection 4: non-sensitive rule' => [
                self::entryJson(['rule' => 'size.file-length']),
                'Config key "sensitiveExclusions[0].rule" must name a sensitive-data rule; "size.file-length" belongs to the "size" pillar.',
                'sensitive',
            ],
        ];
    }

    /**
     * Rejection 5: `path` must name exactly one project-relative file.
     *
     * @return array<string, array{string, string, string}> - config JSON, exact expected message, and the term the diagnostic must mention.
     * @throws JsonException
     */
    private static function pathRejections(): array
    {
        $escapeMessage = 'Config key "sensitiveExclusions[0].path" must be a relative project path that stays inside the project.';

        return [
            'rejection 5: absolute path' => [self::entryJson(['path' => '/etc/corpus/AwsSample.php']), $escapeMessage, 'path'],
            'rejection 5: parent escape' => [self::entryJson(['path' => '../corpus/AwsSample.php']), $escapeMessage, 'path'],
            'rejection 5: glob path'     => [
                self::entryJson(['path' => 'corpus/*.php']),
                'Config key "sensitiveExclusions[0].path" must name exactly one file; glob syntax is not accepted.',
                'path',
            ],
            'rejection 5: missing path'  => [
                self::configJson([['rule' => 'sensitive-data.aws-access-key', 'reason' => 'Synthetic fixture.']]),
                'Config key "sensitiveExclusions[0].path" must be a non-empty string.',
                'path',
            ],
        ];
    }

    /**
     * Rejections 6 to 8: unsupported keys, a missing rationale, and a duplicated scope.
     *
     * @return array<string, array{string, string, string}> - config JSON, exact expected message, and the term the diagnostic must mention.
     * @throws JsonException
     */
    private static function entryRejections(): array
    {
        $reasonMessage     = 'Config key "sensitiveExclusions[0].reason" must be a non-empty string.';
        $unsupportedSuffix = '" is not supported; a sensitive exclusion accepts only rule, path, symbol, reason, and never matches on a message, value, or preview.';

        return [
            'rejection 6: message_contains key' => [
                self::entryJson(['message_contains' => 'AKIA']),
                'Config key "sensitiveExclusions[0].message_contains' . $unsupportedSuffix,
                'message_contains',
            ],
            'rejection 6: messageContains key'  => [
                self::entryJson(['messageContains' => 'AKIA']),
                'Config key "sensitiveExclusions[0].messageContains' . $unsupportedSuffix,
                'messageContains',
            ],
            'rejection 6: value key'            => [
                self::entryJson(['value' => 'AKIA']),
                'Config key "sensitiveExclusions[0].value' . $unsupportedSuffix,
                'value',
            ],
            'rejection 6: preview key'          => [
                self::entryJson(['preview' => '[redacted:aws-access-key]']),
                'Config key "sensitiveExclusions[0].preview' . $unsupportedSuffix,
                'preview',
            ],
            'rejection 7: missing reason'       => [
                self::configJson([['rule' => 'sensitive-data.aws-access-key', 'path' => 'corpus/AwsSample.php']]),
                $reasonMessage,
                'reason',
            ],
            'rejection 7: blank reason'         => [self::entryJson(['reason' => '   ']), $reasonMessage, 'reason'],
            'rejection 8: duplicate scope'      => [
                self::configJson([
                    ['rule' => 'sensitive-data.aws-access-key', 'path' => 'corpus/AwsSample.php', 'reason' => 'First rationale.'],
                    ['rule' => 'sensitive-data.aws-access-key', 'path' => 'corpus/AwsSample.php', 'reason' => 'Second rationale.'],
                ]),
                'Config key "sensitiveExclusions[1]" duplicates the rule, path, and symbol scope already claimed by "sensitiveExclusions[0]"; merge them so one entry owns the scope.',
                'duplicate',
            ],
        ];
    }

    /**
     * Builds a one-entry config from the valid entry with the supplied keys overridden or added, so
     * a rejection row changes exactly one thing.
     *
     * @param array<string, string> $overrides - Entry keys to override or add.
     *
     * @return string - inline JSON config carrying exactly one `sensitiveExclusions` entry.
     * @throws JsonException
     */
    private static function entryJson(array $overrides): string
    {
        return self::configJson([[...self::VALID_ENTRY, ...$overrides]]);
    }

    /**
     * Encodes a whole `sensitiveExclusions` block as the inline JSON the loader reads.
     *
     * @param list<array<string, string>> $entries - Entries to encode, in configuration order.
     *
     * @return string - inline JSON config carrying the supplied entries under `sensitiveExclusions`.
     * @throws JsonException
     */
    private static function configJson(array $entries): string
    {
        return json_encode(['sensitiveExclusions' => $entries], JSON_THROW_ON_ERROR);
    }

    /**
     * Loads an inline config through the real loader and returns the exclusions it produced.
     *
     * @param string $configJson - Inline JSON config to write to disk and load.
     *
     * @return list<SensitiveExclusion> - the loaded exclusions in configuration order; empty when the config declared none.
     */
    private function loadExclusions(string $configJson): array
    {
        $path = $this->writeTempConfig($configJson);

        return (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults())->sensitiveExclusions();
    }
}
