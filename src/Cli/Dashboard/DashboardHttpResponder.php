<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use RuntimeException;

/**
 * Writes a prepared dashboard response out to the browser's socket connection.
 *
 * The last hop of a dashboard request: it serialises the status line, headers, and body of a
 * DashboardHttpResponse onto the accepted client socket, retrying short writes so the whole reply
 * reaches the user's browser even when the connection only accepts a few bytes at a time.
 */
final class DashboardHttpResponder
{
    /**
     * Sends one response to the browser — status line, headers, then body (omitted for HEAD requests).
     *
     * @param resource              $client        - Socket client receiving the dashboard response.
     * @param DashboardHttpResponse $response      - HTTP response to write to the client.
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

        // A HEAD request wants headers only, so skip the body the browser has told us it won't read.
        if (!$isHeadRequest) {
            $this->writeAll($client, $response->body);
        }
    }

    /**
     * Writes every byte of a payload to the socket, looping past short writes until it is all sent.
     *
     * @param resource $client  - Socket client receiving the payload.
     * @param string   $payload - Raw bytes to send in full; the loop retries short writes until every byte is sent.
     *
     * @return void
     */
    private function writeAll($client, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);

        // Sockets can accept fewer bytes than offered, so keep writing until the whole payload is out.
        while ($offset < $length) {
            $written = fwrite($client, substr($payload, $offset));

            // A false or zero write means the browser dropped the connection mid-reply; fail loudly, don't spin.
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write complete dashboard HTTP response.');
            }

            $offset += $written;
        }
    }
}
