<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Command\DashboardHttpResponder;
use GruffPhp\Command\DashboardPageRenderer;
use GruffPhp\Command\DashboardRequestContext;
use GruffPhp\Command\DashboardRequestHandler;
use GruffPhp\Command\DashboardScanRunner;
use GruffPhp\Command\DashboardStateFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

final class DashboardRequestHandlerTest extends TestCase
{
    /**
     * Verify rejects missing host.
     *
     * @return void No return value.
     */
    public function testRejectsMissingHost(): void
    {
        $response = $this->responseFor("GET / HTTP/1.1\r\n\r\n");

        self::assertStringContainsString('HTTP/1.1 421 Misdirected Request', $response);
    }

    /**
     * Verify rejects mismatched host.
     *
     * @return void No return value.
     */
    public function testRejectsMismatchedHost(): void
    {
        $response = $this->responseFor("GET /scan HTTP/1.1\r\nHost: evil.example\r\n\r\n");

        self::assertStringContainsString('HTTP/1.1 421 Misdirected Request', $response);
    }

    /**
     * Verify rejects oversized request line.
     *
     * @return void No return value.
     */
    public function testRejectsOversizedRequestLine(): void
    {
        $response = $this->responseFor('GET /' . str_repeat('a', 8200) . " HTTP/1.1\r\nHost: 127.0.0.1:8765\r\n\r\n");

        self::assertStringContainsString('HTTP/1.1 431 Request Header Fields Too Large', $response);
    }

    /**
     * Verify rejects oversized headers.
     *
     * @return void No return value.
     */
    public function testRejectsOversizedHeaders(): void
    {
        $headers = '';
        for ($i = 0; $i < 101; $i++) {
            $headers .= sprintf("X-Test-%d: value\r\n", $i);
        }

        $response = $this->responseFor("GET / HTTP/1.1\r\nHost: 127.0.0.1:8765\r\n" . $headers . "\r\n");

        self::assertStringContainsString('HTTP/1.1 431 Request Header Fields Too Large', $response);
    }

    /**
     * Verify rejects oversized header block by bytes.
     *
     * @return void No return value.
     */
    public function testRejectsOversizedHeaderBlockByBytes(): void
    {
        $response = $this->responseFor("GET / HTTP/1.1\r\nHost: 127.0.0.1:8765\r\nX-Long: " . str_repeat('a', 17000) . "\r\n\r\n");

        self::assertStringContainsString('HTTP/1.1 431 Request Header Fields Too Large', $response);
    }

    /**
     * Verify allows dashboard health scan head and rejects post.
     *
     * @return void No return value.
     */
    public function testAllowsDashboardHealthScanHeadAndRejectsPost(): void
    {
        self::assertStringContainsString('HTTP/1.1 200 OK', $this->responseFor("GET / HTTP/1.1\r\nHost: localhost:8765\r\n\r\n"));
        self::assertStringContainsString('HTTP/1.1 200 OK', $this->responseFor("GET /health HTTP/1.1\r\n\r\n"));
        self::assertStringContainsString('HTTP/1.1 200 OK', $this->responseFor("GET /scan HTTP/1.1\r\nHost: [::1]:8765\r\n\r\n"));
        self::assertStringContainsString('HTTP/1.1 200 OK', $this->responseFor("HEAD / HTTP/1.1\r\nHost: 127.0.0.1:8765\r\n\r\n"));
        self::assertStringContainsString('HTTP/1.1 405 Method Not Allowed', $this->responseFor("POST /scan HTTP/1.1\r\nHost: 127.0.0.1:8765\r\n\r\n"));
    }

    /**
     * Return the dashboard response for a raw HTTP request.
     *
     * @param string $request Raw HTTP request.
     * @return string Fixture value.
     */
    private function responseFor(string $request): string
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$client, $server] = $pair;
        fwrite($client, $request);
        $this->handler()->handleRequest($server);
        fclose($server);
        $response = stream_get_contents($client);

        fclose($client);

        self::assertIsString($response);

        return $response;
    }

    /**
     * Build a dashboard request handler fixture.
     *
     * @return DashboardRequestHandler Fixture value.
     */
    private function handler(): DashboardRequestHandler
    {
        $stateFactory = new DashboardStateFactory();
        $renderer     = new DashboardPageRenderer();

        return new DashboardRequestHandler(
            new DashboardRequestContext(
                $this->input(),
                dirname(__DIR__, 2),
                dirname(__DIR__, 2),
                5.0,
                '127.0.0.1',
                8765,
            ),
            $stateFactory,
            new DashboardScanRunner($this->fakeGruffBinary(), $stateFactory, $renderer),
            new DashboardHttpResponder(),
        );
    }

    /**
     * Build an empty console input fixture.
     *
     * @return ArrayInput Fixture value.
     */
    private function input(): ArrayInput
    {
        return new ArrayInput([], new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL),
            new InputOption('fail-on', null, InputOption::VALUE_REQUIRED, '', 'none'),
            new InputOption('config', null, InputOption::VALUE_REQUIRED),
            new InputOption('baseline', null, InputOption::VALUE_OPTIONAL),
            new InputOption('no-baseline', null, InputOption::VALUE_NONE),
            new InputOption('no-config', null, InputOption::VALUE_NONE),
            new InputOption('diff', null, InputOption::VALUE_NONE),
            new InputOption('include-ignored', null, InputOption::VALUE_NONE),
        ]));
    }

    /**
     * Create a fake gruff executable for dashboard tests.
     *
     * @return string Fixture value.
     */
    private function fakeGruffBinary(): string
    {
        $path = sys_get_temp_dir() . '/gruff-dashboard-test-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, "<?php echo '<!doctype html><html><body>scan</body></html>'; \n");

        return $path;
    }
}
