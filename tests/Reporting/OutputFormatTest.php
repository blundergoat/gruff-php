<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Output\Reporter\OutputFormat;
use PHPUnit\Framework\TestCase;

/**
 * Tests OutputFormat::isMachineReadable across all declared format cases.
 * Text must return false and every other case must return true.
 * A failure includes the format value to identify the mismatched case.
 */
final class OutputFormatTest extends TestCase
{
    /**
     * Verify only the text format reads as human-oriented; all others are machine-readable.
     *
     * @return void - Asserts false for Text and true for every other declared format case.
     */
    public function testOnlyTextFormatIsNotMachineReadable(): void
    {
        foreach (OutputFormat::cases() as $outputFormat) {
            self::assertSame(
                $outputFormat !== OutputFormat::Text,
                $outputFormat->isMachineReadable(),
                sprintf('Unexpected machine-readability for format "%s".', $outputFormat->value),
            );
        }
    }
}
