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

            return null;
        }

        if (!$this->canRunInfection($projectRoot, $options, $diagnostics)) {
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

            return null;
        }

        return new MutationAnalysisResult($report, $baselineReport, $options->mutationBudget);
    }

    /**
     * @param list<RunDiagnostic> $diagnostics
     * @return bool True when Infection output is available for parsing.
     */
    private function canRunInfection(
        string $projectRoot,
        MutationAnalysisOptions $options,
        array &$diagnostics,
    ): bool {
        if (!$options->shouldRunInfection) {
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

        return false;
    }

    /**
     * @param list<RunDiagnostic> $diagnostics
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
     * @return array{mtime: int, size: int, hash: string}|null Existing report signature, or null when absent.
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
     * Decide whether the report on disk was produced or rewritten by this Infection invocation.
     *
     * A pre-existing report whose mtime, size, and hash have not changed is treated as stale to avoid
     * surfacing outdated mutation results when Infection exits before rewriting it.
     *
     * @param array{mtime: int, size: int, hash: string}|null $preRunSignature Report state before running Infection.
     * @return bool True when the report file was created or changed by this run.
     */
    private function isReportFresh(string $reportPath, ?array $preRunSignature): bool
    {
        if (!is_file($reportPath)) {
            return false;
        }

        if ($preRunSignature === null) {
            return true;
        }

        $currentSignature = $this->reportSignature($reportPath);
        if ($currentSignature === null) {
            return false;
        }

        return $currentSignature['mtime'] > $preRunSignature['mtime']
            || $currentSignature['size'] !== $preRunSignature['size']
            || $currentSignature['hash'] !== $preRunSignature['hash'];
    }
}
