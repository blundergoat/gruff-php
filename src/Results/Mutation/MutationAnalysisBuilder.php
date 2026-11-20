<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Support\PathHelper;

/**
 * Builds mutation results from an Infection report and an optional baseline.
 * When Infection is requested, a freshness check precedes parsing; supplied reports are parsed directly.
 *
 * Option errors and report parsing failures are appended to the caller's diagnostic list.
 */
final readonly class MutationAnalysisBuilder
{
    /**
     * Parses the requested current and optional baseline reports into mutation analysis.
     * With no report path, returns null; dependent flags without that path and parsing failures produce diagnostics.
     *
     * @param string $projectRoot - Base directory used to resolve report paths.
     * @param MutationAnalysisOptions $options - Requested report, runner, baseline, and budget settings.
     * @param list<RunDiagnostic> $diagnostics - Receives option, runner, and report parsing diagnostics by reference.
     *
     * @return MutationAnalysisResult|null - Parsed result; null without a report path, after a rejected run, or on a report parsing failure.
     */
    public function build(
        string $projectRoot,
        MutationAnalysisOptions $options,
        array &$diagnostics,
    ): ?MutationAnalysisResult {
        // No report path means the user did not ask for mutation analysis (or set a flag that needs one without it).
        if ($options->infectionReportPath === null) {
            $this->addOptionDiagnostics($options, $diagnostics);


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

            // A missing, unreadable, or invalid current or baseline report prevents a partial mutation result.
            return null;
        }

        return new MutationAnalysisResult($report, $baselineReport, $options->mutationBudget);
    }

    /**
     * Runs Infection when requested and applies the report freshness gate before parsing.
     *
     * @param string $projectRoot - Base directory for the requested report and Infection process.
     * @param MutationAnalysisOptions $options - Settings controlling whether to run Infection or parse the supplied report directly.
     * @param list<RunDiagnostic> $diagnostics - Receives runner or stale-report diagnostics by reference.
     *
     * @return bool - Whether parsing may proceed; true without launching Infection when the caller supplied a report.
     */
    private function canRunInfection(
        string $projectRoot,
        MutationAnalysisOptions $options,
        array &$diagnostics,
    ): bool {

        if (!$options->shouldRunInfection) {
            // Supplied reports are validated by the parser rather than by the run freshness gate.
            return true;
        }

        // Capture the pre-run signature so the freshness gate can reject a missing or unchanged report afterward.
        $reportPath      = PathHelper::resolveAgainst($projectRoot, $options->infectionReportPath ?? '');
        $preRunSignature = $this->reportSignature($reportPath);

        $runResult = (new InfectionRunner())->runInfection(
            $projectRoot,
            $options->infectionBin,
            $options->infectionConfigPath,
            $options->infectionTestFrameworkOptions,
        );

        // The runner itself failed (say the binary was missing), so surface its diagnostic and give up.
        if ($runResult->diagnostic instanceof RunDiagnostic) {
            $diagnostics[] = $runResult->diagnostic;


            return false;
        }

        clearstatcache(true, $reportPath);


        if ($this->isReportFresh($reportPath, $preRunSignature)) {

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

        // The report is missing, unreadable, or unchanged, so reject it to avoid consuming stale results.
        return false;
    }

    /**
     * Appends usage diagnostics for mutation flags supplied without a report path.
     *
     * @param MutationAnalysisOptions $options - Mutation settings inspected for flags that require an Infection report.
     * @param list<RunDiagnostic> $diagnostics - Receives one usage diagnostic for each unsupported flag setting.
     *
     * @return void - Appends diagnostics without running Infection or parsing a report.
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
     * Samples the report metadata and content hash for a later freshness comparison.
     *
     * @param string $reportPath - Report file whose modification time, size, and SHA-256 hash are sampled.
     *
     * @return array{mtime: int, size: int, hash: string}|null - File signature; null when absent or a metadata/hash read fails.
     */
    private function reportSignature(string $reportPath): ?array
    {

        if (!is_file($reportPath)) {

            return null;
        }

        $mtime = filemtime($reportPath);
        $size  = filesize($reportPath);
        $hash  = hash_file('sha256', $reportPath);


        if (!is_int($mtime) || !is_int($size) || !is_string($hash)) {

            return null;
        }

        return [
            'mtime' => $mtime,
            'size' => $size,
            'hash' => $hash,
        ];
    }

    /**
     * Judges whether the report on disk was produced or rewritten by this run, so gruff never scores an
     * old report Infection left untouched.
     *
     * A pre-existing report whose mtime, size, and hash have not changed is treated as stale, to avoid
     * surfacing outdated mutation results when Infection exits before rewriting it.
     *
     * @param string $reportPath - Path to the report on disk, re-sampled after the run.
     * @param array{mtime: int, size: int, hash: string}|null $preRunSignature - Report state captured before running Infection; null when no report existed then.
     *
     * @return bool - True when the report file was created or changed by this run; false when it is missing, unreadable, or unchanged (stale).
     */
    private function isReportFresh(string $reportPath, ?array $preRunSignature): bool
    {
        // Infection wrote no report at all, so there is nothing fresh to consume.
        if (!is_file($reportPath)) {
            // Infection wrote no report at all, so there is nothing fresh to consume.
            return false;
        }

        // Nothing existed before the run, so whatever is here now was produced by it.
        if ($preRunSignature === null) {
            // No report existed before the run, so any file present now was produced by it.
            return true;
        }

        $currentSignature = $this->reportSignature($reportPath);
        // The report vanished or became unreadable mid-check, so we cannot confirm it is fresh.
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
