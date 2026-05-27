<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Rule\Naming\IdentifierTokenizer;
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
    }
}
