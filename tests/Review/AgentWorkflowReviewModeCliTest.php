<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers review-mode CLI option validation that does not need branch-review fixtures.
 * Checks --changed-only without --diff-vs and conflicting --diff and --diff-vs options.
 * Asserts exit code 2 and diagnostic text for both invocations.
 */
final class AgentWorkflowReviewModeCliTest extends TestCase
{
    /** Project root used by CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify invalid review-mode option combinations report usage errors.
     *
     * @return void - Asserts exit code 2 and the expected diagnostic for each tested option combination.
     */
    public function testReviewModeInvalidOptionCombinationsFailEarly(): void
    {
        $changedOnlyProcess = new Process([
                                              PHP_BINARY,
                                              self::PROJECT_ROOT . '/bin/gruff-php',
                                              'analyse',
                                              '--changed-only',
                                          ], self::PROJECT_ROOT);
        $changedOnlyProcess->run();

        self::assertSame(2, $changedOnlyProcess->getExitCode(), $changedOnlyProcess->getOutput() . $changedOnlyProcess->getErrorOutput());
        self::assertStringContainsString('--changed-only requires --diff-vs.', $changedOnlyProcess->getOutput());

        $diffConflictProcess = new Process([
                                               PHP_BINARY,
                                               self::PROJECT_ROOT . '/bin/gruff-php',
                                               'analyse',
                                               '--diff',
                                               'working-tree',
                                               '--diff-vs=HEAD',
                                           ], self::PROJECT_ROOT);
        $diffConflictProcess->run();

        self::assertSame(2, $diffConflictProcess->getExitCode(), $diffConflictProcess->getOutput() . $diffConflictProcess->getErrorOutput());
        self::assertStringContainsString('--diff, --since, --changed-ranges, and --diff-vs are mutually exclusive.', $diffConflictProcess->getOutput());
    }
}
