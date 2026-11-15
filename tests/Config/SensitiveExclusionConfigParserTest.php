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
 * Checks which sensitiveExclusions entries the real configuration loader accepts or rejects.
 * Accepted cases check stored fields, entry order, and the empty result when the block is absent.
 * Rejected cases expect ConfigException messages containing the supplied diagnostic text.
 */
final class SensitiveExclusionConfigParserTest extends ConfigLoaderTestCase
{
    /**
     * Supplies valid defaults so entryJson() can isolate an overridden or additional field.
     *
     * @var array<string, string> - Default rule, path, and rationale used by entryJson().
     */
    private const VALID_ENTRY = [
        'rule'   => 'sensitive-data.aws-access-key',
        'path'   => 'corpus/AwsSample.php',
        'reason' => 'Synthetic fixture.',
    ];

    /**
     * Verifies a file-scoped entry loads with its rule, path, and rationale intact and no symbol.
     *
     * @return void - Asserts one entry's rule, path, reason, and null symbol.
     * @throws JsonException - If encoding a configuration fixture as JSON fails.
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
     * Verifies that an optional symbol is retained on a loaded exclusion.
     *
     * @return void - Asserts one loaded entry with the supplied symbol.
     * @throws JsonException - If encoding a configuration fixture as JSON fails.
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
     * Verifies that two configured exclusions load in the supplied rule order.
     *
     * @return void - Asserts two entries and their rule IDs in configuration order.
     * @throws JsonException - If encoding a configuration fixture as JSON fails.
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
     * Verifies that a standalone config without sensitiveExclusions loads an empty exclusion list.
     *
     * @return void - Asserts that the config produces no sensitive exclusions.
     */
    public function testOmittedBlockSuppressesNothing(): void
    {
        self::assertSame([], $this->loadExclusions('{"minimumPhpVersion":8.3}'));
    }

    /**
     * Checks that an invalid exclusion makes configuration loading raise ConfigException.
     * The expected message text must name the required term and occur in the exception message.
     *
     * @param string $configJson - Inline JSON config carrying the rejected sensitiveExclusions shape.
     * @param string $expectedMessage - Text that the thrown ConfigException message must contain.
     * @param string $requiredMention - Term the expected message text must contain.
     *
     * @return void - Asserts the required term and expects ConfigException with the specified message text.
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
     * Supplies invalid exclusion configurations and diagnostic expectations for the loader test.
     *
     * @return array<string, array{string, string, string}> - Named rows of config JSON, required message text, and a required term.
     * @throws JsonException - If encoding a configuration fixture as JSON fails.
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
     * Provide cases for rejecting values that do not name one known sensitive-data rule ID.
     *
     * @return array<string, array{string, string, string}> - Named rows of config JSON, required message text, and a required term.
     * @throws JsonException - If encoding a configuration fixture as JSON fails.
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
     * Provide cases for rejecting paths that do not name exactly one project-relative file.
     *
     * @return array<string, array{string, string, string}> - Named rows of config JSON, required message text, and a required term.
     * @throws JsonException - If encoding a configuration fixture as JSON fails.
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
     * Provide cases for rejecting unsupported keys, missing rationales, and duplicate scopes.
     *
     * @return array<string, array{string, string, string}> - Named rows of config JSON, required message text, and a required term.
     * @throws JsonException - If encoding a configuration fixture as JSON fails.
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
     * Builds a one-entry rejection fixture from valid defaults and supplied field changes.
     *
     * @param array<string, string> $overrides - Entry keys to override or add.
     *
     * @return string - inline JSON config carrying exactly one sensitiveExclusions entry.
     * @throws JsonException - If encoding a configuration fixture as JSON fails.
     */
    private static function entryJson(array $overrides): string
    {
        return self::configJson([[...self::VALID_ENTRY, ...$overrides]]);
    }

    /**
     * Encodes a whole sensitiveExclusions block as the inline JSON the loader reads.
     *
     * @param list<array<string, string>> $entries - Entries to encode, in configuration order.
     *
     * @return string - inline JSON config carrying the supplied entries under sensitiveExclusions.
     * @throws JsonException - If JSON encoding of the entries fails.
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
     * @return list<SensitiveExclusion> - Resolved exclusions in configuration order; an omitted block retains any inherited entries.
     */
    private function loadExclusions(string $configJson): array
    {
        $path = $this->writeTempConfig($configJson);

        return (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults())->sensitiveExclusions();
    }
}
