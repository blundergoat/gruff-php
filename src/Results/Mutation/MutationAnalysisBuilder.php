<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Support\PathHelper;

/**
 * Turns a mutation run into a result gruff can score, guarding against stale or missing Infection output.
 *
 * This is the gatekeeper between "the user asked for mutation analysis" and "gruff has a trustworthy
 * report to read". Depending on the user's flags it either trusts a report they generated, or runs
 * Infection itself and then checks the report was genuinely rewritten before believing it. Every
 * failure path - missing report, misused flags, a run that left a stale file - becomes a diagnostic and
 * a null result, so a broken mutation setup degrades to a clear note instead of wrong numbers.
 */
final readonly class MutationAnalysisBuilder
{
    /**
     * Produces a mutation result for the run, or null with a diagnostic when there is nothing
     * trustworthy to read - so mutation analysis is either right or absent, never misleading.
     *
     * @param string                  $projectRoot - Project root used to resolve the mutation report paths.
     * @param MutationAnalysisOptions $options - Mutation-analysis options the user selected for the run.
     * @param list<RunDiagnostic>     $diagnostics - By-reference sink; any usage or parse error is appended here for the reporters to show.
     *
     * @return MutationAnalysisResult|null - The parsed mutation result; null when no report was requested, Infection produced nothing usable, or the report was malformed.
     */
    public function build(
        string $projectRoot,
        MutationAnalysisOptions $options,
        array &$diagnostics,
    ): ?MutationAnalysisResult {
        // No report path means the user did not ask for mutation analysis (or set a flag that needs one without it).
        if ($options->infectionReportPath === null) {
            $this->addOptionDiagnostics($options, $diagnostics);

            // Without a report path there is nothing to read; mutation analysis is simply absent, not an error.
            return null;
        }

        // Infection was meant to run but could not produce usable output, so stop here.
        if (!$this->canRunInfection($projectRoot, $options, $diagnostics)) {
            // Infection could not produce usable output; the diagnostic was already recorded by the gate.
            return null;
        }

        $infectionReportParser = new InfectionReportParser($projectRoot);

        try {
            $report         = $infectionReportParser->parse($options->infectionReportPath);
            // Only load a baseline report when the user set one to compare this run against.
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

        return new MutationAnalysisResult($report, $baselineReport, $options->mutationBudget);
    }

    /**
     * Decides whether there is Infection output worth parsing - trusting an existing report, or running
     * Infection and confirming it actually refreshed the file before believing it.
     *
     * @param string                  $projectRoot - Anchor for resolving the report path Infection writes to.
     * @param MutationAnalysisOptions $options - Run options; decide whether Infection is invoked or its report trusted as-is.
     * @param list<RunDiagnostic>     $diagnostics - By-reference sink; a failure diagnostic is appended when the run cannot be trusted.
     *
     * @return bool - True when a trustworthy Infection report is available to parse; false when the run failed or left a stale report.
     */
    private function canRunInfection(
        string $projectRoot,
        MutationAnalysisOptions $options,
        array &$diagnostics,
    ): bool {
        // The user did not ask gruff to run Infection, so take the report they already generated as-is.
        if (!$options->shouldRunInfection) {
            // Caller opted not to run Infection, so trust the pre-existing report on disk as-is.
            return true;
        }

        // Note the report's state before the run, so afterwards we can tell whether Infection actually rewrote it.
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

            // The runner itself failed (e.g. binary missing); propagate its diagnostic and stop.
            return false;
        }

        clearstatcache(true, $reportPath);

        // The report was created or rewritten by this run, so it reflects the code we just tested.
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
     * Flags mutation options the user set without the `--infection-report` they depend on, so a misuse
     * is explained rather than silently ignored.
     *
     * @param MutationAnalysisOptions $options - Options whose flags set without --infection-report count as misuse.
     * @param list<RunDiagnostic>     $diagnostics - By-reference sink; one usage-error is appended per stray flag.
     *
     * @return void
     */
    private function addOptionDiagnostics(MutationAnalysisOptions $options, array &$diagnostics): void
    {
        // --infection-run needs a report path to read Infection's JSON from, so on its own it is a misuse.
        if ($options->shouldRunInfection) {
            $diagnostics[] = new RunDiagnostic(
                type:    'usage-error',
                message: '--infection-run requires --infection-report because Infection writes full JSON through configured log paths.',
            );
        }

        // --infection-config only means something when Infection is actually run against a report.
        if ($options->infectionConfigPath !== null) {
            $diagnostics[] = new RunDiagnostic(
                type:    'usage-error',
                message: '--infection-config only applies with --infection-run and --infection-report.',
                path:    $options->infectionConfigPath,
            );
        }

        // --infection-test-framework-options likewise only applies to an actual Infection run.
        if ($options->infectionTestFrameworkOptions !== null) {
            $diagnostics[] = new RunDiagnostic(
                type:    'usage-error',
                message: '--infection-test-framework-options only applies with --infection-run and --infection-report.',
            );
        }

        // --mutation-baseline needs a report to compare against.
        if ($options->mutationBaselinePath !== null) {
            $diagnostics[] = new RunDiagnostic(
                type:    'usage-error',
                message: '--mutation-baseline requires --infection-report.',
                path:    $options->mutationBaselinePath,
            );
        }

        // --mutation-budget needs a report to measure survivors against.
        if ($options->mutationBudget !== null) {
            $diagnostics[] = new RunDiagnostic(
                type:    'usage-error',
                message: '--mutation-budget requires --infection-report.',
            );
        }
    }

    /**
     * Samples a report file's mtime, size, and hash, so a later re-sample can tell whether Infection
     * rewrote it.
     *
     * @param string $reportPath - Absolute path to the JSON report whose mtime, size, and hash are sampled.
     *
     * @return array{mtime: int, size: int, hash: string}|null - The report's signature; null when the file is absent or any stat call failed, so freshness cannot be judged from it.
     */
    private function reportSignature(string $reportPath): ?array
    {
        // No file yet means there is no prior state to compare against.
        if (!is_file($reportPath)) {
            // No file yet means no prior state to compare against; null signals "absent", not "error".
            return null;
        }

        $mtime = filemtime($reportPath);
        $size  = filesize($reportPath);
        $hash  = hash_file('sha256', $reportPath);

        // If any stat call failed the signature is incomplete, so the whole sample is unusable.
        if (!is_int($mtime) || !is_int($size) || !is_string($hash)) {
            // Any stat call failing leaves an incomplete signature, so treat the whole sample as unusable.
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
