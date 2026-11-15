<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Rules\Naming\IdentifierTokenizer;
use PHPUnit\Framework\TestCase;

/**
 * Covers tokenization of mixed-case, underscore-separated, and lowercase identifiers.
 * Checks that a leading underscore is discarded and acronym boundaries split into words.
 * Checks that trailing digit runs remain separate tokens, including a run with a leading zero.
 */
final class IdentifierTokenizerTest extends TestCase
{
    /**
     * Verify identifier tokenizer splits common identifier shapes.
     *
     * @return void - Asserts exact token lists for case changes, underscores, acronyms, and digits.
     */
    public function testIdentifierTokenizerSplitsCommonIdentifierShapes(): void
    {
        $identifierTokenizer = new IdentifierTokenizer();

        self::assertSame(['http', 'response', 'code'], $identifierTokenizer->tokenize('HTTPResponseCode'));
        self::assertSame(['order', 'item', '2'], $identifierTokenizer->tokenize('order_item2'));
        self::assertSame(['temp'], $identifierTokenizer->tokenize('_temp'));
        self::assertSame(['assistant', 'intent', 'requires', 'context'], $identifierTokenizer->tokenize('assistantIntentRequiresContext'));
        self::assertSame(['focus', 'mode', 'payload', 'present'], $identifierTokenizer->tokenize('focus_mode_payload_present'));
        self::assertSame(['unrequested'], $identifierTokenizer->tokenize('unrequested'));
    }

    /**
     * Verify digit runs remain separate tokens without losing the leading zero in adr020.
     *
     * @return void - Asserts exact word and digit tokens for hash, encoding, version, and ADR-style identifiers.
     */
    public function testIdentifierTokenizerPreservesDigitIdentity(): void
    {
        $identifierTokenizer = new IdentifierTokenizer();

        self::assertSame(['sha', '256'], $identifierTokenizer->tokenize('sha256'));
        self::assertSame(['utf', '8'], $identifierTokenizer->tokenize('utf8'));
        self::assertSame(['base', '64'], $identifierTokenizer->tokenize('base64'));
        self::assertSame(['v', '2'], $identifierTokenizer->tokenize('v2'));
        self::assertSame(['adr', '020'], $identifierTokenizer->tokenize('adr020'));
    }
}
