<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers branch-review behavior when mutation input is present.
 */
final class BranchReviewMutationCliTest extends TestCase
{
    /** Project root used by CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify branch review score delta ignores mutation-only score inputs.
     *
     * @throws JsonException When CLI JSON output cannot be decoded.
     *
     * @return void
     */
    public function testBranchReviewDeltaExcludesMutationInput(): void
    {
        $this->skipWhenGitIsUnavailable();
        $repo = $this->tempDir();

        try {
            self::assertTrue(mkdir($repo . '/src', 0777, true));
            $this->runGit($repo, 'init');
            $this->runGit($repo, 'config', 'user.email', 'test@example.com');
            $this->runGit($repo, 'config', 'user.name', 'Gruff Test');
            file_put_contents($repo . '/src/Target.php', "<?php\n/** Fixture file. */\n\$value = 1;\n");
            file_put_contents($repo . '/src/Unrelated.php', "<?php\n");
            $this->runGit($repo, 'add', 'src/Target.php', 'src/Unrelated.php');
            $this->runGit($repo, 'commit', '-m', 'base');

            file_put_contents($repo . '/src/Target.php', "<?php\n/** Fixture file. */\n\$value = 2;\n");
            file_put_contents($repo . '/infection.json', $this->infectionReportJson());

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                'src',
                '--format=json',
                '--fail-on=none',
                '--no-config',
                '--no-baseline',
                '--diff-vs=HEAD',
                '--changed-only',
                '--infection-report=infection.json',
            ], $repo);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $report   = $this->decodeJson($process);
            $mutation = $this->phpTopLevelExtension($report, 'mutation');
            $totals   = $this->arrayValue($mutation, 'totals');
            $review   = $this->phpTopLevelExtension($report, 'review');

            self::assertEquals(50.0, $totals['msi'] ?? null);
            self::assertEqualsWithDelta(0.0, $this->floatValue($review, 'deltaScore'), 0.001);
        } finally {
            $this->removeDir($repo);
        }
    }

    /**
     * Return a minimal Infection report with non-perfect mutation score.
     *
     * @return string - JSON report fixture.
     */
    private function infectionReportJson(): string
    {
        // One killed and one escaped mutant pin msi to 50.0, the non-perfect score the delta-score assertion expects.
        return <<<'JSON'
{
  "stats": {
    "totalMutantsCount": 2,
    "killedCount": 1,
    "escapedCount": 1,
    "timedOutCount": 0,
    "msi": 50.0,
    "coveredCodeMsi": 50.0,
    "mutationCodeCoverage": 100.0
  },
  "escaped": [
    {
      "mutator": {
        "mutatorName": "Plus",
        "originalFilePath": "src/Unrelated.php",
        "originalStartLine": 1
      },
      "diff": "-1\n+2",
      "processOutput": "Failed asserting."
    }
  ],
  "killed": [
    {
      "mutator": {
        "mutatorName": "Minus",
        "originalFilePath": "src/Unrelated.php",
        "originalStartLine": 1
      },
      "diff": "-2\n+1",
      "processOutput": ""
    }
  ],
  "timeouted": [],
  "killedByStaticAnalysis": [],
  "errored": [],
  "syntaxErrors": [],
  "uncovered": [],
  "ignored": []
}
JSON;
    }

    /**
     * Decode CLI JSON output into a string-keyed payload.
     *
     * @param Process $process - finished CLI process whose stdout must hold the report JSON; caller runs it first.
     *
     * @return array<string, mixed> - Decoded CLI report.
     * @throws JsonException When CLI output is invalid JSON.
     */
    private function decodeJson(Process $process): array
    {
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        return $this->stringKeyedArray($decoded);
    }

    /**
     * Read a nested string-keyed array from a payload.
     *
     * @param array<string, mixed> $payload - Source payload.
     * @param string               $key     - key whose value must itself be a string-keyed array; missing key asserts.
     *
     * @return array<string, mixed> - Nested payload.
     */
    private function arrayValue(array $payload, string $key): array
    {
        return $this->stringKeyedArray($payload[$key] ?? null);
    }

    /**
     * Read a PHP-owned v3 top-level extension from decoded analysis output.
     *
     * @param array<string, mixed> $payload - Decoded v3 analysis document.
     * @param string               $key     - PHP extension key to read.
     *
     * @return array<string, mixed> - Validated PHP extension object.
     */
    private function phpTopLevelExtension(array $payload, string $key): array
    {
        $extensions    = $this->arrayValue($payload, 'extensions');
        $phpExtensions = $this->arrayValue($extensions, 'php');
        $topLevel      = $this->arrayValue($phpExtensions, 'topLevel');

        return $this->arrayValue($topLevel, $key);
    }

    /**
     * Read a numeric value from a payload as float.
     *
     * @param array<string, mixed> $payload - Source payload.
     * @param string               $key     - key whose value must be int or float; a non-numeric value fails the test.
     *
     * @return float - Numeric payload value.
     */
    private function floatValue(array $payload, string $key): float
    {
        $payloadValue = $payload[$key] ?? null;
        self::assertTrue(is_int($payloadValue) || is_float($payloadValue));

        // Cast int-or-float to float so JSON whole numbers (e.g. 0) compare cleanly against float expectations.
        return (float) $payloadValue;
    }

    /**
     * Assert a decoded JSON value is an array with string keys.
     *
     * @param mixed $payload - decoded JSON value expected to be a JSON object; arrays and scalars fail the assertion.
     *
     * @return array<string, mixed> - String-keyed payload.
     */
    private function stringKeyedArray(mixed $payload): array
    {
        self::assertIsArray($payload);

        $result = [];
        foreach ($payload as $key => $entryValue) {
            self::assertIsString($key);
            $result[$key] = $entryValue;
        }

        return $result;
    }

    /**
     * Skip the current test when Git is unavailable.
     *
     * @return void
     */
    private function skipWhenGitIsUnavailable(): void
    {
        $process = new Process(['git', '--version']);
        $process->run();

        if (!$process->isSuccessful()) {
            self::markTestSkipped('git is not available.');
        }
    }

    /**
     * Run a Git command in a fixture repository.
     *
     * @param string $cwd  - Working directory.
     * @param string $args - Command arguments.
     *
     * @return void
     */
    private function runGit(string $cwd, string ...$args): void
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string - Fixture directory.
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-review-mutation-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path - Filesystem path to delete.
     *
     * @return void
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            // Nothing to clean up when the fixture directory was never created (e.g. an early test failure).
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        foreach ($items as $directoryEntry) {
            if ($directoryEntry === '.' || $directoryEntry === '..') {
                continue;
            }

            $child = $path . '/' . $directoryEntry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
