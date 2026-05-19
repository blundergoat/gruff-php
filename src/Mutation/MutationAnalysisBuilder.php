<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

use GruffPhp\Analysis\RunDiagnostic;
use Symfony\Component\Console\Command\Command;

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

        $reportPath  = $this->absolutePath($projectRoot, $options->infectionReportPath ?? '');
        $preRunMtime = null;

        if (is_file($reportPath)) {
            $mtime       = filemtime($reportPath);
            $preRunMtime = $mtime === false ? null : $mtime;
        }

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

        if ($runResult->exitCode === Command::SUCCESS || $this->isReportFresh($reportPath, $preRunMtime)) {
            return true;
        }

        $diagnostics[] = new RunDiagnostic(
            type:    'mutation-run-error',
            message: sprintf(
                'Infection exited with code %d before producing the requested report.',
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
     * Resolve a configured mutation path against the analysed project root.
     *
     * @return string Absolute path for the supplied mutation-related file.
     */
    private function absolutePath(string $projectRoot, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($projectRoot, '/') . '/' . $path;
    }

    /**
     * Decide whether the report on disk was produced by this Infection invocation.
     *
     * A pre-existing report whose mtime has not advanced is treated as stale to avoid
     * surfacing outdated mutation results when Infection exits before rewriting it.
     *
     * @return bool True when the report file was (re)written after $preRunMtime.
     */
    private function isReportFresh(string $reportPath, ?int $preRunMtime): bool
    {
        if (!is_file($reportPath)) {
            return false;
        }

        if ($preRunMtime === null) {
            return true;
        }

        $currentMtime = filemtime($reportPath);

        return is_int($currentMtime) && $currentMtime > $preRunMtime;
    }
}
