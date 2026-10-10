<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Rules\Docs\MissingConstantPhpdocRule;
use GruffPhp\Rules\Docs\MissingPropertyPhpdocRule;

/**
 * Pins documentation coverage by structure: a comment above the first declaration
 * of an unbroken run covers the run, unless another declaration in the run has its own comment. A blank line or any
 * other statement ends a run.
 */
final class DeclarationRunCoverageTest extends DocsRuleTestCase
{
    /**
     * Verify a comment above a run's first constant covers the run by structure rather than by its words,
     * unless another constant in the run has its own comment.
     *
     * @return void
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
     * Verify a docblock above a run's first property covers the run, while a later docblock, a blank line, or a
     * line comment under the default docblock policy leaves the other properties to stand alone.
     *
     * @return void
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
     * Return finding symbols in stable lexical order.
     *
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Findings to summarize.
     *
     * @return list<string> - Sorted finding symbols; a missing symbol becomes an empty string and fails expectations.
     */
    private static function symbols(array $findings): array
    {
        $symbols = array_map(static fn($finding): string => $finding->symbol ?? '', $findings);
        sort($symbols);

        return $symbols;
    }
}
