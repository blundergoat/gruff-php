<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Reporting;

use GruffPhp\Reporting\OutputFormat;
use PHPUnit\Framework\TestCase;

/**
 * Covers OutputFormat::isMachineReadable: every analyse format except the human-oriented text report counts as machine-readable.
 */
final class OutputFormatTest extends TestCase
{
    /**
     * Verify only the text format reads as human-oriented; all others are machine-readable.
     *
     * @return void
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
