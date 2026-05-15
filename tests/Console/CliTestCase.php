<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Covers CliTestCase behavior.
 */
abstract class CliTestCase extends TestCase
{
    /** Project root used by CLI fixture helpers. */
    protected const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Create an isolated project fixture for baseline CLI tests.
     *
     * @return string Fixture value.
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
     * @return array<string, mixed>
     * @throws JsonException
     */
    protected function decodeJsonOutput(Process $process): array
    {
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        $report = [];

        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $report[$key] = $value;
        }

        return $report;
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string Fixture value.
     */
    protected function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-cli-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Filesystem path.
     * @return void No return value.
     */
    protected function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
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
     * @param string $source      Source directory.
     * @param string $destination Destination directory.
     * @return void No return value.
     */
    protected function copyPackageTree(string $source, string $destination): void
    {
        self::assertTrue(mkdir($destination, 0777, true));

        $source   = rtrim($source, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($source): bool {
                    $name = $file->getFilename();

                    if ($file->isDir() && in_array($name, ['.git', 'vendor', 'node_modules', '.idea'], true)) {
                        return false;
                    }

                    $relativePath = substr($file->getPathname(), strlen($source) + 1);
                    $relativePath = str_replace('\\', '/', $relativePath);

                    foreach (['.goat-flow/logs/', '.goat-flow/scratchpad/', '.goat-flow/tasks/'] as $ignoredPrefix) {
                        if (str_starts_with($relativePath, $ignoredPrefix)) {
                            return false;
                        }
                    }

                    return true;
                },
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            self::assertInstanceOf(\SplFileInfo::class, $item);
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $targetPath   = $destination . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    self::assertTrue(mkdir($targetPath, 0777, true));
                }

                continue;
            }

            self::assertTrue(copy($item->getPathname(), $targetPath));
        }
    }

    /**
     * Return an unused local TCP port for dashboard tests.
     *
     * @return int Fixture value.
     * @throws RuntimeException When the helper cannot complete the fixture operation.
     */
    protected function unusedPort(): int
    {
        $warningMessage = null;
        set_error_handler(static function (int $severity, string $message) use (&$warningMessage): bool {
            $warningMessage = $message;

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

        if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $matches)) {
            throw new RuntimeException('Unable to read allocated test port.');
        }

        return (int) $matches[1];
    }

    /**
     * Wait for the dashboard HTTP server to accept connections.
     *
     * @param int     $port    Local TCP port.
     * @param Process $process Dashboard server process.
     * @return void No return value.
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
                    return;
                }
            } catch (RuntimeException) {
                usleep(50_000);
            }
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for gruff dashboard server. ' . $process->getErrorOutput() . $process->getOutput());
    }

    /**
     * Fetch a raw response from the local dashboard server.
     *
     * @param int    $port Local TCP port.
     * @param string $path Filesystem path.
     * @return string Fixture value.
     * @throws RuntimeException When the helper cannot complete the fixture operation.
     */
    protected function fetchHttp(int $port, string $path): string
    {
        $warningMessage = null;
        set_error_handler(static function (int $severity, string $message) use (&$warningMessage): bool {
            $warningMessage = $message;

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
