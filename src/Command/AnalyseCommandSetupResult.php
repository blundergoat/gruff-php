<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Reporting\OutputFormat;
use Symfony\Component\Console\Command\Command;

final readonly class AnalyseCommandSetupResult
{
    private function __construct(
        public ?AnalyseCommandSetup $setup,
        public ?AnalysisReport $report,
        public ?OutputFormat $format,
        public ?string $plainError,
        public int $exitCode,
    ) {
    }

    public static function ready(AnalyseCommandSetup $setup): self
    {
        return new self($setup, null, null, null, Command::SUCCESS);
    }

    public static function plainError(string $message, int $exitCode): self
    {
        return new self(null, null, null, $message, $exitCode);
    }

    public static function reportError(AnalysisReport $report, OutputFormat $format): self
    {
        return new self(null, $report, $format, null, Command::INVALID);
    }
}
