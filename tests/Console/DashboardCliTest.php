<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Covers the dashboard CLI command: refreshable HTML server, mutation UI suppression when configured, and ad-hoc scans of other projects via browser query.
 */
final class DashboardCliTest extends CliTestCase
{
    /**
     * Raw sensitive snippets that dashboard scans must never render.
     *
     * @var list<string>
     */
    private const SENSITIVE_SNIPPETS = ['MIIBVgIBADANBgkqhkiG', 's3cr3tValue', 'Tok3nXyZ9'];

    /**
     * Verify dashboard command serves refreshable HTML report.
     *
     * @return void
     */
    public function testDashboardCommandServesRefreshableHtmlReport(): void
    {
        $port    = $this->unusedPort();
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'dashboard',
            'tests/Fixtures/Source/Code',
            '--host',
            '127.0.0.1',
            '--port',
            (string) $port,
            '--project-root',
            self::PROJECT_ROOT,
            '--scan-timeout',
            '30',
        ], self::PROJECT_ROOT);
        $process->setTimeout(null);
        $process->start();

        try {
            $this->waitForHttpServer($port, $process);

            $response = $this->fetchHttp($port, '/');

            self::assertStringContainsString('HTTP/1.1 200 OK', $response);
            self::assertStringContainsString('gruff-php dashboard', $response);
            self::assertStringContainsString('controls-toggle', $response);
            self::assertStringContainsString('Dashboard controls', $response);
            self::assertStringContainsString('Project root', $response);
            self::assertStringContainsString('copy-scan-meta', $response);
            self::assertStringContainsString('name="reportInteractive"', $response);
            self::assertStringContainsString('name="scanScope"', $response);
            self::assertStringContainsString('whole branch', $response);
            self::assertStringContainsString('diff only', $response);
            self::assertStringContainsString('value=".gruff-php.yaml"', $response);
            self::assertStringContainsString('class="field-grid"', $response);

            $scan = $this->fetchHttp($port, '/scan');

            self::assertStringContainsString('HTTP/1.1 200 OK', $scan);
            self::assertStringContainsString('gruff-dashboard-meta', $scan);
            self::assertStringNotContainsString('gruff-dashboard-toolbar', $scan);
            self::assertStringContainsString('<section class="verdict">', $scan);
            self::assertStringNotContainsString('finding-filters', $scan);

            $interactiveScan = $this->fetchHttp($port, '/scan?reportInteractive=1');

            self::assertStringContainsString('HTTP/1.1 200 OK', $interactiveScan);
            self::assertStringContainsString('class="finding-filters"', $interactiveScan);
            self::assertStringContainsString('--report-interactive', $interactiveScan);

            $diffScan = $this->fetchHttp($port, '/scan?scanScope=diff');

            self::assertStringContainsString('HTTP/1.1 200 OK', $diffScan);
            self::assertStringContainsString('--diff', $diffScan);
        } finally {
            $process->stop(1);
        }
    }

    /**
     * Verify dashboard scan omits mutation UI.
     *
     * @return void
     */
    public function testDashboardScanOmitsMutationUi(): void
    {
        $port    = $this->unusedPort();
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'dashboard',
            'tests/Fixtures/Source/Code',
            '--host',
            '127.0.0.1',
            '--port',
            (string) $port,
            '--scan-timeout',
            '30',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->setTimeout(null);
        $process->start();

        try {
            $this->waitForHttpServer($port, $process);

            $scan = $this->fetchHttp($port, '/scan');

            self::assertStringContainsString('HTTP/1.1 200 OK', $scan);
            self::assertStringContainsString('Mutation is omitted when no Infection report is supplied.', $scan);
            self::assertStringNotContainsString('<div class="name">mutation</div>', $scan);
            self::assertStringNotContainsString('MSI', $scan);
        } finally {
            $process->stop(1);
        }
    }

    /**
     * Verify dashboard scans do not leak raw sensitive-data snippets.
     *
     * @return void
     */
    public function testDashboardScanDoesNotLeakSensitiveDataSecrets(): void
    {
        $port    = $this->unusedPort();
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'dashboard',
            'tests/Fixtures/SensitiveData/gcp-service-account-key.json',
            'tests/Fixtures/SensitiveData/url-credentials.php',
            '--host',
            '127.0.0.1',
            '--port',
            (string) $port,
            '--scan-timeout',
            '30',
            '--fail-on',
            'none',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->setTimeout(null);
        $process->start();

        try {
            $this->waitForHttpServer($port, $process);

            $scan = $this->fetchHttp($port, '/scan');

            self::assertStringContainsString('HTTP/1.1 200 OK', $scan);
            foreach (self::SENSITIVE_SNIPPETS as $sensitiveSnippet) {
                self::assertStringNotContainsString($sensitiveSnippet, $scan, 'Dashboard scan leaked a raw secret.');
            }
            self::assertStringContainsString('redacted', $scan);
        } finally {
            $process->stop(1);
        }
    }

    /**
     * Verify dashboard command can scan another project from browser query.
     *
     * @return void
     */
    public function testDashboardCommandCanScanAnotherProjectFromBrowserQuery(): void
    {
        $tempDir = $this->tempDir();
        $port    = $this->unusedPort();
        file_put_contents($tempDir . '/Example.php', "<?php\n\nfinal class Example\n{\n    public function run(): void {}\n}\n");

        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'dashboard',
            'tests/Fixtures/Source/Code',
            '--host',
            '127.0.0.1',
            '--port',
            (string) $port,
            '--scan-timeout',
            '30',
        ], self::PROJECT_ROOT);
        $process->setTimeout(null);
        $process->start();

        try {
            $this->waitForHttpServer($port, $process);

            $scan = $this->fetchHttp($port, '/scan?project=' . rawurlencode($tempDir) . '&paths=.');

            self::assertStringContainsString('HTTP/1.1 200 OK', $scan);
            self::assertStringContainsString($tempDir, $scan);
            self::assertStringContainsString('Example.php', $scan);
        } finally {
            $process->stop(1);
            $this->removeDir($tempDir);
        }
    }
}
