<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Finding;

use GruffPhp\Results\Finding\DeclarationInventory;
use PHPUnit\Framework\TestCase;

/**
 * Checks source-line inventories for inline var annotations and property declarations.
 * Covers promoted, attributed, parenthesised, and multi-name properties.
 * Verifies docblock start lines while excluding property docblocks and ordinary parameters.
 */
final class DeclarationInventoryTest extends TestCase
{
    /**
     * Verify a docblock's @var tag is listed at the docblock's start line and a property at its own line.
     *
     * @return void - Asserts annotation, property, and promoted-property lines while excluding the ordinary parameter.
     */
    public function testScanListsVarDocblocksAndPropertyLines(): void
    {
        $source = implode("\n", [
            '<?php',
            'final class Pair',
            '{',
            '    private int $count = 0;',
            '    private array $functionMap = [];',
            '',
            '    public function __construct(',
            '        public readonly string $label,',
            '    ) {',
            '    }',
            '',
            '    public function read(array $input): int',
            '    {',
            '        /** @var int $value the caller validated this key */',
            "        \$value = \$input['a'];",
            '        /**',
            '         * @var int $value',
            '         */',
            "        \$value = \$input['b'];",
            '',
            '        return $value;',
            '    }',
            '}',
        ]);

        $positions = DeclarationInventory::scan($source);

        self::assertSame([14, 16], $positions['$value'] ?? null);
        self::assertSame([4], $positions['$count'] ?? null);
        self::assertSame([5], $positions['$functionMap'] ?? null);
        self::assertSame([8], $positions['$label'] ?? null);
        self::assertArrayNotHasKey('$input', $positions);
    }

    /**
     * Verify a property's own docblock adds no position, and attribute-led, parenthesised and multi-name properties are listed.
     *
     * @return void - Asserts each declared property uses its declaration line without a separate docblock position.
     */
    public function testScanReadsEveryPropertyShapeAndSkipsDeclarationDocblocks(): void
    {
        $source = implode("\n", [
            '<?php',
            'final class Settings',
            '{',
            '    /** @var list<string> $names */',
            '    private array $names = [];',
            '    #[Column(length: 32)] private int $count = 0;',
            '    private (Countable&Stringable)|null $label = null;',
            '    private int $width = 1, $height = 2;',
            '}',
        ]);

        $positions = DeclarationInventory::scan($source);

        self::assertSame([5], $positions['$names'] ?? null);
        self::assertSame([6], $positions['$count'] ?? null);
        self::assertSame([7], $positions['$label'] ?? null);
        self::assertSame([8], $positions['$width'] ?? null);
        self::assertSame([8], $positions['$height'] ?? null);
    }
}
