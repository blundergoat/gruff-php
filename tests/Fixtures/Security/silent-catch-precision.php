<?php

declare(strict_types=1);

/**
 * Quiet: each comment gives a reason for the silence.
 *
 * @param callable $work - Work that may throw.
 *
 * @return void
 */
function explainedSilence(callable $work): void
{
    try {
        $work();
    } catch (\Exception) {
        // Destructors must not throw.
    }
    try {
        $work();
    } catch (\Exception) {
        // Fall back to the cached copy.
    }
    try {
        $work();
    } catch (\Exception) {
        // Retry anyway in-case the socket closed early.
    }
    try {
        $work();
    } catch (\Exception) {
        // The lock is best effort.
        // Skip it when another worker holds it.
    }
    try {
        $work();
    } catch (\Exception) {
        // can't log here, the logger is down
    }
}

/**
 * Reported: each comment restates the silence, leaves a task, or disables code.
 *
 * @param callable $work - Work that may throw.
 *
 * @return void
 */
function unexplainedSilence(callable $work): void
{
    try {
        $work();
    } catch (\Exception) {
        // do nothing
    }
    try {
        $work();
    } catch (\Exception) {
        // Catch exceptions and remain silent.
    }
    try {
        $work();
    } catch (\Exception) {
        // ignore if
    }
    try {
        $work();
    } catch (\Exception) {
        // TODO: log this when the logger exists
    }
    try {
        $work();
    } catch (\Exception $error) {
        // if ($error) { error_log($error->getMessage()); }
    }
    try {
        $work();
    } catch (\Exception) {
        // don't care
    }
}

/**
 * Quiet: the code reads the caught value after the try.
 *
 * @param callable $work - Work that may throw.
 *
 * @return void
 */
function handledAfterward(callable $work): void
{
    $failure = null;
    try {
        $work();
    } catch (\Exception $failure) {
    }
    if ($failure !== null) {
        error_log($failure->getMessage());
    }
    try {
        $work();
    } catch (\Exception $captured) {
    }
    $report = static function () use ($captured): void {
        error_log((string) $captured);
    };
    $report();
}

/**
 * Reported: the caught value is never read, or is replaced or dropped before any read.
 *
 * @param callable $work - Work that may throw.
 *
 * @return void
 */
function droppedAfterward(callable $work): void
{
    try {
        $work();
    } catch (\Exception $unused) {
    }
    try {
        $work();
    } catch (\Exception $rebound) {
    }
    try {
        $work();
    } catch (\RuntimeException $rebound) {
        error_log($rebound->getMessage());
    }
    try {
        $work();
    } catch (\Exception $overwritten) {
    }
    $overwritten = null;
    error_log((string) $overwritten);
    try {
        $work();
    } catch (\Exception $dropped) {
    }
    unset($dropped);
    try {
        $work();
    } catch (\Exception $probed) {
    }
    if (isset($probed)) {
        error_log('failed');
    }
    try {
        $work();
    } catch (\Exception $looped) {
    }
    foreach ([1, 2] as $looped) {
        error_log((string) $looped);
    }
    try {
        $work();
    } catch (\Exception $declared) {
    }
    function declaredLater(\Exception $declared): void
    {
        error_log($declared->getMessage());
    }
}
