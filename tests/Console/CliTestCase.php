<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Shared filesystem and report assertions for CLI integration tests.
 * Builds baseline and security fixtures and copies package trees into isolated projects.
 * Dashboard helpers allocate local TCP ports and wait for an HTTP health response.
 */
abstract class CliTestCase extends TestCase
{
    /** Project root used by CLI fixture helpers. */
    protected const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Create an isolated project fixture for baseline CLI tests.
     *
     * @return string - absolute path to the freshly created project root the CLI test should scan
     */
    protected function createBaselineProject(): string
    {
        $project = $this->tempDir();
        self::assertTrue(mkdir($project . '/src', 0777, true));

        $fixture = file_get_contents(self::PROJECT_ROOT . '/tests/Fixtures/Source/Code/OrderCalculator.php');
        self::assertIsString($fixture);
        file_put_contents($project . '/src/OrderCalculator.php', $fixture);
        // Provide a README so docs.missing-readme does not add an extra baseline entry.
        file_put_contents($project . '/README.md', "Baseline workflow fixture.\n");

        return $project;
    }

    /**
     * Decode the CLI process's stdout and assert the report array has string keys.
     *
     * @param Process $process - Completed CLI process whose stdout holds the JSON report.
     *
     * @return array<string, mixed> - Report fields; empty JSON objects and arrays both decode to an empty array.
     * @throws JsonException - When stdout is invalid JSON or exceeds the decoder's depth limit.
     */
    protected function decodeJsonOutput(Process $process): array
    {
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        return $this->decodedJsonObject($decoded);
    }

    /**
     * Assert that a decoded value is an array with string keys.
     *
     * @param mixed $value - Decoded value to check; non-arrays and integer-keyed arrays fail assertions.
     *
     * @return array<string, mixed> - The same entries; an empty array is accepted without establishing its JSON origin.
     */
    protected function decodedJsonObject(mixed $value): array
    {
        self::assertIsArray($value);

        $object = [];
        foreach ($value as $key => $entryValue) {
            self::assertIsString($key);
            $object[$key] = $entryValue;
        }

        return $object;
    }

    /**
     * Traverse decoded report keys, asserting an array with string keys at each selected value.
     *
     * @param array<string, mixed> $payload - Report array to traverse; returned unchanged when no keys are supplied.
     * @param string ...$keys - Ordered field names; missing or null values fail the array assertion.
     *
     * @return array<string, mixed> - Selected array, or the unchanged payload when no keys are given.
     */
    protected function decodedJsonObjectAt(array $payload, string ...$keys): array
    {
        foreach ($keys as $key) {
            $payload = $this->decodedJsonObject($payload[$key] ?? null);
        }

        return $payload;
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string - absolute path to the newly created, empty temp directory the caller owns and must clean up
     */
    protected function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-cli-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        return $path;
    }

    /**
     * Copy the synthetic-secrets fixture into a temporary project's src/ directory for secret-rule CLI tests.
     * Use a production-style path so the built-in test-path filter does not hide secret findings.
     *
     * @return string - Root of the temporary project; the caller removes it with removeDir().
     */
    protected function syntheticSecretsProject(): string
    {
        $project = $this->tempDir();
        self::assertTrue(mkdir($project . '/src'));
        self::assertTrue(copy(self::PROJECT_ROOT . '/tests/Fixtures/SensitiveData/synthetic-secrets.php', $project . '/src/synthetic-secrets.php'));

        return $project;
    }

    /**
     * Copies the cumulative-security fixture into a temporary project's src/, so security rules read it as production code.
     * A test that needs security findings uses this, because every security rule skips tests/ and Fixtures/ paths.
     *
     * @return string - Root of the temporary project; the caller removes it with removeDir().
     */
    protected function cumulativeSecurityProject(): string
    {
        $project = $this->tempDir();
        self::assertTrue(mkdir($project . '/src'));
        self::assertTrue(copy(self::PROJECT_ROOT . '/tests/Fixtures/Security/cumulative-security.php', $project . '/src/cumulative-security.php'));

        return $project;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path - Root of the temporary directory tree.
     *
     * @return void - Attempts recursive cleanup; non-directory paths are left unchanged.
     */
    protected function removeDir(string $path): void
    {
        if (!is_dir($path)) {

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

    /**
     * Copy the package tree into an isolated test project.
     *
     * @param string $source - Package root whose retained entries are copied.
     * @param string $destination - New fixture root to create; it must not already exist.
     *
     * @return void - Creates the filtered fixture tree and asserts each directory creation and file copy succeeds.
     */
    protected function copyPackageTree(string $source, string $destination): void
    {
        self::assertTrue(mkdir($destination, 0777, true));

        $source                    = rtrim($source, '/');
        $recursiveIteratorIterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($source): bool {
                    $name = $file->getFilename();

                    if ($file->isDir() && in_array($name, ['.git', 'vendor', 'node_modules', '.idea'], true)) {
                        // Prune heavy or environment-specific trees the fixture must not carry.
                        return false;
                    }

                    $relativePath = substr($file->getPathname(), strlen($source) + 1);
                    $relativePath = str_replace('\\', '/', $relativePath);

                    foreach (['.goat-flow/logs/', '.goat-flow/scratchpad/', '.goat-flow/tasks/'] as $ignoredPrefix) {
                        if (str_starts_with($relativePath, $ignoredPrefix)) {
                            // Drop volatile workspace state so the copied fixture stays deterministic.
                            return false;
                        }
                    }


                    return true;
                },
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($recursiveIteratorIterator as $sourceEntry) {
            self::assertInstanceOf(\SplFileInfo::class, $sourceEntry);
            $relativePath = substr($sourceEntry->getPathname(), strlen($source) + 1);
            $targetPath   = $destination . '/' . $relativePath;

            if ($sourceEntry->isDir()) {
                if (!is_dir($targetPath)) {
                    self::assertTrue(mkdir($targetPath, 0777, true));
                }

                continue;
            }

            self::assertTrue(copy($sourceEntry->getPathname(), $targetPath));
        }
    }

    /**
     * Return an unused local TCP port for dashboard tests.
     *
     * @return int - an OS-assigned ephemeral TCP port that was free at allocation time and is no longer bound, for the dashboard test to bind
     * @throws RuntimeException - When socket creation fails or its bound address lacks a readable port.
     */
    protected function unusedPort(): int
    {
        $warningMessage = null;
        set_error_handler(static function (int $severity, string $message) use (&$warningMessage): bool {
            $warningMessage = $message;

            // Returning true suppresses PHP's default warning so the bind failure surfaces via our message.
            return true;
        });

        try {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        } finally {
            restore_error_handler();
        }

        if ($server === false) {
            throw new RuntimeException(sprintf(
                                           'Unable to allocate test port: %s (%d)',
                                           $errorMessage !== '' ? $errorMessage : ($warningMessage ?? 'unknown error'),
                                           $errorCode,
                                       ));
        }

        $name = stream_socket_get_name($server, false);
        fclose($server);

        // The socket name's trailing decimal component is the OS-assigned port the dashboard test will bind.
        if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $matches)) {
            throw new RuntimeException('Unable to read allocated test port.');
        }

        return (int)$matches[1];
    }

    /**
     * Wait until the dashboard health response contains the expected HTTP 200 status text.
     *
     * @param int $port - Local TCP port serving the dashboard.
     * @param Process $process - Started server process checked for premature exit.
     *
     * @return void - Returns on the expected status text, or fails if the process exits or the readiness check times out.
     */
    protected function waitForHttpServer(int $port, Process $process): void
    {
        $deadline = microtime(true) + 5.0;

        do {
            if (!$process->isRunning()) {
                self::fail($process->getErrorOutput() . $process->getOutput());
            }

            try {
                $response = $this->fetchHttp($port, '/health');

                if (str_contains($response, "HTTP/1.1 200 OK\r\n")) {
                    // Treat the expected /health status text as readiness; a TCP connection alone is not enough for this test.

                    return;
                }
            } catch (RuntimeException) {
                // Connection/read failures can precede startup; retry quietly because exit/timeout reports the server output.
                usleep(50_000);
            }
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for gruff-php dashboard server. ' . $process->getErrorOutput() . $process->getOutput());
    }

    /**
     * Fetch a raw response from the local dashboard server.
     *
     * @param int $port - Local dashboard port to connect to.
     * @param string $path - HTTP request target sent after GET, including any query string.
     *
     * @return string - Raw socket bytes, which may be empty or partial; HTTP structure and timeout status are not validated.
     * @throws RuntimeException - When connecting fails or reading returns false.
     */
    protected function fetchHttp(int $port, string $path): string
    {
        $warningMessage = null;
        set_error_handler(static function (int $severity, string $message) use (&$warningMessage): bool {
            $warningMessage = $message;

            // Returning true suppresses PHP's default warning so the connect failure surfaces via our message.
            return true;
        });

        try {
            $socket = stream_socket_client(sprintf('tcp://127.0.0.1:%d', $port), $errorCode, $errorMessage, 1.0);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                                           'Unable to connect to report server: %s (%d)',
                                           $errorMessage !== '' ? $errorMessage : ($warningMessage ?? 'unknown error'),
                                           $errorCode,
                                       ));
        }

        stream_set_timeout($socket, 5);
        fwrite($socket, sprintf("GET %s HTTP/1.1\r\nHost: 127.0.0.1:%d\r\nConnection: close\r\n\r\n", $path, $port));
        $response = stream_get_contents($socket);
        fclose($socket);

        if (!is_string($response)) {
            throw new RuntimeException('Unable to read HTTP response.');
        }

        return $response;
    }
}
