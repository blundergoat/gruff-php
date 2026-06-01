<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use RuntimeException;

/**
 * Writes dashboard HTTP responses to an accepted socket client.
 */
final class DashboardHttpResponder
{
    /**
     * @param resource              $client - Socket client receiving the dashboard response.
     * @param DashboardHttpResponse $response - HTTP response to write to the client.
     * @param bool                  $isHeadRequest - Whether the body should be omitted for a HEAD request.
     *
     * @return void
     */
    public function write($client, DashboardHttpResponse $response, bool $isHeadRequest): void
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

        $this->writeAll($client, implode("\r\n", $headers));

        if (!$isHeadRequest) {
            $this->writeAll($client, $response->body);
        }
    }

    /**
     * @param resource $client - Socket client receiving the payload.
     * @param string   $payload - Raw bytes to send in full; the loop retries short writes until every byte is sent.
     *
     * @return void
     */
    private function writeAll($client, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $written = fwrite($client, substr($payload, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write complete dashboard HTTP response.');
            }

            $offset += $written;
        }
    }
}
