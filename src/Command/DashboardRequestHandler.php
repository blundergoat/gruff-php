<?php

declare(strict_types=1);

namespace GruffPhp\Command;

final readonly class DashboardRequestHandler
{
    public function __construct(
        private DashboardRequestContext $context,
        private DashboardStateFactory $stateFactory,
        private DashboardScanRunner $scanRunner,
        private DashboardHttpResponder $responder,
    ) {
    }

    /**
     * @param resource $client
     */
    public function handleRequest($client): void
    {
        $request = $this->request($client);

        if ($request === null) {
            $this->responder->write($client, new DashboardHttpResponse(400, 'Bad Request', 'Bad Request', 'text/plain; charset=UTF-8'), false);

            return;
        }

        $this->drainHeaders($client);

        $this->responder->write(
            $client,
            $this->responseFor($request['method'], $request['target']),
            $request['method'] === 'HEAD',
        );
    }

    /**
     * @param resource $client
     * @return array{method: string, target: string}|null
     */
    private function request($client): ?array
    {
        $requestLine = fgets($client);

        if (!is_string($requestLine) || !preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/\d(?:\.\d)?\r?\n$/', $requestLine, $matches)) {
            return null;
        }

        return [
            'method' => $matches[1],
            'target' => $matches[2],
        ];
    }

    private function responseFor(string $method, string $target): DashboardHttpResponse
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return new DashboardHttpResponse(405, 'Method Not Allowed', 'Method Not Allowed', 'text/plain; charset=UTF-8');
        }

        $path = parse_url($target, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $query = $this->query($target);

        return match ($path) {
            '/health' => new DashboardHttpResponse(200, 'OK', 'ok', 'text/plain; charset=UTF-8'),
            '/favicon.ico' => new DashboardHttpResponse(204, 'No Content', '', 'text/plain; charset=UTF-8'),
            '/' => new DashboardHttpResponse(200, 'OK', $this->dashboardHtml($query), 'text/html; charset=UTF-8'),
            '/scan' => new DashboardHttpResponse(200, 'OK', $this->scanRunner->scanHtml($this->context, $query), 'text/html; charset=UTF-8'),
            default => new DashboardHttpResponse(404, 'Not Found', 'Not Found', 'text/plain; charset=UTF-8'),
        };
    }

    /**
     * @param array<string, string> $query
     */
    private function dashboardHtml(array $query): string
    {
        $state = $this->stateFactory->state($this->context->input, $this->context->projectRoot, $query);

        return (new DashboardPageRenderer())->dashboardHtml($state);
    }

    /**
     * @return array<string, string>
     */
    private function query(string $target): array
    {
        $queryString = parse_url($target, PHP_URL_QUERY);

        if (!is_string($queryString) || $queryString === '') {
            return [];
        }

        parse_str($queryString, $query);
        $clean = [];

        foreach ($query as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            $clean[$key] = (string) $value;
        }

        return $clean;
    }

    /**
     * @param resource $client
     */
    private function drainHeaders($client): void
    {
        while (($line = fgets($client)) !== false) {
            if ($line === "\r\n" || $line === "\n") {
                return;
            }
        }
    }
}
