<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Output\Reporter\OutputFormat;
use Symfony\Component\Console\Command\Command;

/**
 * Result of preparing the `analyse` command - either a ready-to-run setup or an early stop.
 *
 * The setup phase can succeed, fail with a plain console error, fail with a report-formatted error
 * (so `--format=json` still returns structured output), or bow out after another component already
 * printed something. This one type carries whichever outcome occurred, plus the exit code to return.
 */
final readonly class AnalyseCommandSetupResult
{
    /**
     * Create a setup result variant.
     *
     * @param AnalyseCommandSetup|null $setup      - Ready setup payload, when setup succeeded.
     * @param AnalysisReport|null      $report     - Report-formatted setup error payload, when available.
     * @param OutputFormat|null        $format     - Output format for a report-formatted setup error.
     * @param string|null              $plainError - Plain console error message, when setup failed before report formatting.
     * @param int                      $exitCode   - Symfony command exit code for this result.
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
     * Wraps a validated setup so the command proceeds straight to the scan.
     *
     * @param AnalyseCommandSetup $setup - Setup exposed to the caller for starting analysis.
     *
     * @return self - Result containing the setup and Command::SUCCESS, with all error payloads null.
     */
    public static function ready(AnalyseCommandSetup $setup): self
    {

        return new self($setup, null, null, null, Command::SUCCESS);
    }

    /**
     * Stops with an unformatted console message - for setup failures before an output format is known.
     *
     * @param string $message  - Plain error message for console output.
     * @param int    $exitCode - Symfony command exit code for the failure.
     *
     * @return self - Result exposing the message and supplied exit code without a setup or report.
     */
    public static function plainError(string $message, int $exitCode): self
    {

        return new self(null, null, null, $message, $exitCode);
    }

    /**
     * Stop setup without requesting additional error output from the caller.
     *
     * @param int $exitCode - Symfony command exit code for the early exit.
     *
     * @return self - Result containing the supplied exit code with all payloads null.
     */
    public static function exitCode(int $exitCode): self
    {

        return new self(null, null, null, null, $exitCode);
    }

    /**
     * Fails through the report formatter, so machine formats like --format=json still return structured output.
     *
     * @param AnalysisReport $report - Report payload describing the setup failure.
     * @param OutputFormat   $format - Output format selected for the report.
     *
     * @return self - Result containing the report and format with Command::INVALID and no ready setup.
     */
    public static function reportError(AnalysisReport $report, OutputFormat $format): self
    {

        return new self(null, $report, $format, null, Command::INVALID);
    }
}
