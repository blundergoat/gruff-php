<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Reporting\OutputFormat;
use Symfony\Component\Console\Command\Command;

/**
 * Represents either ready analysis setup or an early command error.
 */
final readonly class AnalyseCommandSetupResult
{
    /**
     * Create a setup result variant.
     *
     * @param AnalyseCommandSetup|null $setup - Ready setup payload, when setup succeeded.
     * @param AnalysisReport|null      $report - Report-formatted setup error payload, when available.
     * @param OutputFormat|null        $format - Output format for a report-formatted setup error.
     * @param string|null              $plainError - Plain console error message, when setup failed before report formatting.
     * @param int                      $exitCode - Symfony command exit code for this result.
     */
    private function __construct(
        public ?AnalyseCommandSetup $setup,
        public ?AnalysisReport $report,
        public ?OutputFormat $format,
        public ?string $plainError,
        public int $exitCode,
    ) {
    }

    /**
     * Build a successful setup result.
     *
     * @param AnalyseCommandSetup $setup - Ready setup payload.
     *
     * @return self - Ready setup result.
     */
    public static function ready(AnalyseCommandSetup $setup): self
    {
        // Success variant: only the setup payload is populated and the exit code is zero.
        return new self($setup, null, null, null, Command::SUCCESS);
    }

    /**
     * Build a plain console error result.
     *
     * @param string $message - Plain error message for console output.
     * @param int    $exitCode - Symfony command exit code for the failure.
     *
     * @return self - Plain error setup result.
     */
    public static function plainError(string $message, int $exitCode): self
    {
        // Error variant carrying only an unformatted console message; the caller prints it and exits non-zero.
        return new self(null, null, null, $message, $exitCode);
    }

    /**
     * Build an early exit result after another component already wrote output.
     *
     * @param int $exitCode - Symfony command exit code for the early exit.
     *
     * @return self - Early exit setup result.
     */
    public static function exitCode(int $exitCode): self
    {
        // Early-exit variant with no payload: another component already wrote output, so only the code is honoured.
        return new self(null, null, null, null, $exitCode);
    }

    /**
     * Build a report-formatted setup error result.
     *
     * @param AnalysisReport $report - Report payload describing the setup failure.
     * @param OutputFormat   $format - Output format selected for the report.
     *
     * @return self - Report error setup result.
     */
    public static function reportError(AnalysisReport $report, OutputFormat $format): self
    {
        // Error variant whose failure is rendered through the report formatter; exit code is the invalid-usage code.
        return new self(null, $report, $format, null, Command::INVALID);
    }
}
