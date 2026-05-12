<?php

declare(strict_types=1);

namespace GruffPhp\Command;

/**
 * Writes dashboard HTTP responses to an accepted socket client.
 */
final class DashboardHttpResponder
{
    /**
     * @param resource              $client   Socket client receiving the dashboard response.
     * @param DashboardHttpResponse $response HTTP response to write to the client.
     * @param bool                  $headOnly Whether the body should be omitted for a HEAD request.
     * @return void
     */
    public function write($client, DashboardHttpResponse $response, bool $headOnly): void
    {
        $headers = [
            sprintf('HTTP/1.1 %d %s', $response->statusCode, $response->reasonPhrase),
            'Content-Type: ' . $response->contentType,
            'Content-Length: ' . strlen($response->body),
            'Cache-Control: no-store',
            'Connection: close',
            '',
            '',
        ];

        fwrite($client, implode("\r\n", $headers));

        if (!$headOnly) {
            fwrite($client, $response->body);
        }
    }
}
