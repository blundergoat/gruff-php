<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Support\PathHelper;

/**
 * Builds mutation analysis results from Infection execution and reports.
 */
final readonly class MutationAnalysisBuilder
{
    /**
     * @param string                  $projectRoot Project root used to resolve mutation report paths.
     * @param MutationAnalysisOptions $options     Mutation-analysis options selected for the run.
     * @param list<RunDiagnostic>     $diagnostics Diagnostics collected while loading mutation data.
     * @return MutationAnalysisResult|null Mutation report result when one can be built.
     */
    public function build(
        string $projectRoot,
        MutationAnalysisOptions $options,
        array &$diagnostics,
    ): ?MutationAnalysisResult {
        if ($options->infectionReportPath === null) {
            $this->addOptionDiagnostics($options, $diagnostics);

            // Without a report path there is nothing to read; mutation analysis is simply absent, not an error.
            return null;
        }

        if (!$this->canRunInfection($projectRoot, $options, $diagnostics)) {
            // Infection could not produce usable output; the diagnostic was already recorded by the gate.
            return null;
        }

        $infectionReportParser = new InfectionReportParser($projectRoot);

        try {
            $report         = $infectionReportParser->parse($options->infectionReportPath);
            $baselineReport = $options->mutationBaselinePath === null
                ? null
                : $infectionReportParser->parse($options->mutationBaselinePath);
        } catch (MutationReportException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'mutation-report-error',
                message: $exception->getMessage(),
                path:    $options->infectionReportPath,
            );

            // A malformed report is reported as a parse error and yields no result rather than a partial one.
            return null;
        }

        // Both reports parsed; bundle them with the budget so callers can compute deltas and budget checks.
        return new MutationAnalysisResult($report, $baselineReport, $options->mutationBudget);
    }

    /**
     * @param string                  $projectRoot Anchor for resolving the report path Infection writes to.
     * @param MutationAnalysisOptions $options     Run options; gates whether Infection is invoked or trusted as-is.
     * @param list<RunDiagnostic>     $diagnostics Run-by-reference sink; a failure diagnostic is appended on error.
     * @return bool True when Infection output is available for parsing.
     */
    private function canRunInfection(
        string $projectRoot,
        MutationAnalysisOptions $options,
        array &$diagnostics,
    ): bool {
        if (!$options->shouldRunInfection) {
            // Caller opted not to run Infection, so trust the pre-existing report on disk as-is.
            return true;
        }

        $reportPath      = PathHelper::resolveAgainst($projectRoot, $options->infectionReportPath ?? '');
        $preRunSignature = $this->reportSignature($reportPath);

        $runResult = (new InfectionRunner())->runInfection(
            $projectRoot,
            $options->infectionBin,
            $options->infectionConfigPath,
            $options->infectionTestFrameworkOptions,
        );

        if ($runResult->diagnostic instanceof RunDiagnostic) {
            $diagnostics[] = $runResult->diagnostic;

            // The runner itself failed (e.g. binary missing); propagate its diagnostic and stop.
            return false;
        }

        clearstatcache(true, $reportPath);

        if ($this->isReportFresh($reportPath, $preRunSignature)) {
            // Report was created or rewritten by this run, so it reflects the current code.
            return true;
        }

        $diagnostics[] = new RunDiagnostic(
            type:    'mutation-run-error',
            message: sprintf(
                'Infection exited with code %d before producing or rewriting the requested report.',
                $runResult->exitCode,
            ),
            path: $options->infectionReportPath,
        );

        // Infection exited without refreshing the report, so the stale file is rejected to avoid false results.
        return false;
    }

    /**
     * Append diagnostics for invalid or skipped mutation options.
     *
     * @param MutationAnalysisOptions $options     Options whose flags set without --infection-report count as misuse.
     * @param list<RunDiagnostic>     $diagnostics Run-by-reference sink; one usage-error is appended per stray flag.
     * @return void
     */
    private function addOptionDiagnostics(MutationAnalysisOptions $options, array &$diagnostics): void
    {
        if ($options->shouldRunInfection) {
            $diagnostics[] = new RunDiagnostic(
                type:    'usage-error',
                message: '--infection-run requires --infection-report because Infection writes full JSON through configured log paths.',
            );
        }

        if ($options->infectionConfigPath !== null) {
            $diagnostics[] = new RunDiagnostic(
                type:    'usage-error',
                message: '--infection-config only applies with --infection-run and --infection-report.',
                path:    $options->infectionConfigPath,
            );
        }

        if ($options->infectionTestFrameworkOptions !== null) {
            $diagnostics[] = new RunDiagnostic(
                type:    'usage-error',
                message: '--infection-test-framework-options only applies with --infection-run and --infection-report.',
            );
        }

        if ($options->mutationBaselinePath !== null) {
            $diagnostics[] = new RunDiagnostic(
                type:    'usage-error',
                message: '--mutation-baseline requires --infection-report.',
                path:    $options->mutationBaselinePath,
            );
        }

        if ($options->mutationBudget !== null) {
            $diagnostics[] = new RunDiagnostic(
                type:    'usage-error',
                message: '--mutation-budget requires --infection-report.',
            );
        }
    }

    /**
     * Capture the report state before an Infection run.
     *
     * @param string $reportPath Absolute path to the JSON report whose mtime, size, and hash are sampled.
     * @return array{mtime: int, size: int, hash: string}|null Existing report signature, or null when absent.
     */
    private function reportSignature(string $reportPath): ?array
    {
        if (!is_file($reportPath)) {
            // No file yet means no prior state to compare against; null signals "absent", not "error".
            return null;
        }

        $mtime = filemtime($reportPath);
        $size  = filesize($reportPath);
        $hash  = hash_file('sha256', $reportPath);

        if (!is_int($mtime) || !is_int($size) || !is_string($hash)) {
            // Any stat call failing leaves an incomplete signature, so treat the whole sample as unusable.
            return null;
        }

        // Triple of mtime, size, and hash; comparing all three catches same-size rewrites a plain mtime check misses.
        return [
            'mtime' => $mtime,
            'size' => $size,
            'hash' => $hash,
        ];
    }

    /**
     * Decide whether the report on disk was produced or rewritten by this Infection invocation.
     *
     * A pre-existing report whose mtime, size, and hash have not changed is treated as stale to avoid
     * surfacing outdated mutation results when Infection exits before rewriting it.
     *
     * @param string $reportPath Path to the report on disk, re-sampled after the run.
     * @param array{mtime: int, size: int, hash: string}|null $preRunSignature Report state before running Infection.
     * @return bool True when the report file was created or changed by this run.
     */
    private function isReportFresh(string $reportPath, ?array $preRunSignature): bool
    {
        if (!is_file($reportPath)) {
            // Infection wrote no report at all, so there is nothing fresh to consume.
            return false;
        }

        if ($preRunSignature === null) {
            // No report existed before the run, so any file present now was produced by it.
            return true;
        }

        $currentSignature = $this->reportSignature($reportPath);
        if ($currentSignature === null) {
            // The report vanished or became unreadable mid-check; cannot confirm freshness, so reject it.
            return false;
        }

        // Fresh only if any tracked attribute moved; an unchanged triple means Infection left the old file in place.
        return $currentSignature['mtime'] > $preRunSignature['mtime']
            || $currentSignature['size'] !== $preRunSignature['size']
            || $currentSignature['hash'] !== $preRunSignature['hash'];
    }
}
