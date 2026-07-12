<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Rules\Naming\IdentifierTokenizer;
use PHPUnit\Framework\TestCase;

/**
 * Covers IdentifierTokenizer's case- and underscore-split behaviour. Split out
 * of NamingRulesTest to keep that suite's public-method count under the
 * `size.public-method-count` error threshold.
 */
final class IdentifierTokenizerTest extends TestCase
{
    /**
     * Verify identifier tokenizer splits common identifier shapes.
     *
     * @return void
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
     * Verify digit runs survive tokenization as their own tokens (P1).
     *
     * Digits and acronyms carry identity: `sha256`, `utf8`, `v2` are exact,
     * correct names. The tokenizer must keep each digit run as a standalone token
     * rather than deleting it or folding it into the stem, so a downstream naming
     * rule never mistakes `sha256` for a `foo1`-style numbered placeholder. This
     * is the DESIGN-PRINCIPLES P1 rubric.
     *
     * @return void
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
