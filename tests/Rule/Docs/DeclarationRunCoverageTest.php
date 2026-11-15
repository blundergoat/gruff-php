<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Rules\Docs\MissingConstantPhpdocRule;
use GruffPhp\Rules\Docs\MissingPropertyPhpdocRule;

/**
 * Checks shared documentation for unbroken runs of constants and properties.
 * Constants accept leading local comments; properties require docblocks under the default policy.
 * Later individual documentation prevents sharing, while blank lines end a run.
 */
final class DeclarationRunCoverageTest extends DocsRuleTestCase
{
    /**
     * Verify leading PHPDoc and local comments cover the constant runs in the fixture.
     * A later individual comment prevents sharing, and a blank line leaves the next constant uncovered.
     *
     * @return void - Asserts that only RETRY_THIRD and TIMEOUT_READ lack accepted documentation.
     */
    public function testMissingConstantPhpdocCoversAnUnbrokenRunByStructure(): void
    {
        $findings = $this->analyseSourceRule(<<<'PHP'
<?php
final class ConstantGroups
{
    /**
     * Accounting map type: which settings section a mapping row belongs to.
     */
    public const TYPE_REVENUE = 1;
    public const TYPE_EXPENSE = 2;
    public const TYPE_PAYMENT = 3;

    // Entity-based read/write access denied messages shown to the user.
    public const DENIED_ALERT = 'Cannot find alert or you do not have access';
    public const DENIED_INVOICE = 'Cannot find invoice or you do not have access';

    // Regex patterns for parsing provider references.
    public const PATTERN_A = 'a', PATTERN_B = 'b', PATTERN_C = 'c', PATTERN_D = 'd';
    public const PATTERN_E = 'e', PATTERN_F = 'f', PATTERN_G = 'g';

    // Connection limits for the upstream mail relay.
    private const RETRY_FIRST = 1;
    // The second retry waits for the relay's own back-off.
    private const RETRY_SECOND = 2;
    private const RETRY_THIRD = 3;

    // Timeouts for the upstream mail relay, in seconds.
    private const TIMEOUT_CONNECT = 5;

    private const TIMEOUT_READ = 30;
}
PHP, MissingConstantPhpdocRule::ID);

        self::assertSame(['ConstantGroups::RETRY_THIRD', 'ConstantGroups::TIMEOUT_READ'], self::symbols($findings));
    }

    /**
     * Verify a docblock on the first property covers its declaration run.
     * Later docblocks, blank lines and line comments leave subsequent properties to stand alone under the default policy.
     *
     * @return void - Asserts that jitter, password, readTimeout and user are the only reported properties.
     */
    public function testDocblockAboveARunsFirstPropertyCoversTheRun(): void
    {
        $symbols = self::symbols($this->analyseSourceRule(<<<'PHP'
<?php
final class RelayClient
{
    /** Connection settings for the upstream mail relay, read once at start-up. */
    public string $host = '';
    public int $port = 25;
    public bool $secure = false;

    /** Retry policy applied to each send. */
    public int $retries = 3;
    /** Seconds to wait between retries. */
    public int $backoff = 1;
    public int $jitter = 0;

    /** Timeout for one send, in seconds. */
    public int $timeout = 30;

    public int $readTimeout = 60;

    // Credentials loaded from the environment.
    public string $user = '';
    public string $password = '';
}
PHP, MissingPropertyPhpdocRule::ID));

        self::assertSame([
            'RelayClient::$jitter',
            'RelayClient::$password',
            'RelayClient::$readTimeout',
            'RelayClient::$user',
        ], $symbols);
    }

    /**
     * Return finding symbols in sorted order for assertions.
     *
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Findings whose symbols form the assertion input.
     *
     * @return list<string> - Sorted symbols with duplicates retained; null symbols become empty strings.
     */
    private static function symbols(array $findings): array
    {
        $symbols = array_map(static fn($finding): string => $finding->symbol ?? '', $findings);
        sort($symbols);

        return $symbols;
    }
}
