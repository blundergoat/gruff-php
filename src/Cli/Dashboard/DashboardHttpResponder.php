<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use RuntimeException;

/**
 * Writes a prepared dashboard response to an accepted client socket.
 *
 * Serialises the status line, headers, and optional body, retrying short writes.
 * A failed or zero-byte write raises RuntimeException so the server can report an incomplete response.
 */
final class DashboardHttpResponder
{
    /**
     * Sends one response to the browser - status line, headers, then body (omitted for HEAD requests).
     *
     * @param resource              $client        - Socket client receiving the dashboard response.
     * @param DashboardHttpResponse $response      - HTTP response to write to the client.
     * @param bool                  $isHeadRequest - Whether the body should be omitted for a HEAD request.
     *
     * @return void - Writes response headers and, except for HEAD, the body.
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
     * Writes the payload in full, retrying short writes and rejecting writes that make no progress.
     *
     * @param resource $client  - Socket client receiving the payload.
     * @param string   $payload - Bytes to write; an empty string requires no socket writes.
     *
     * @return void - Writes the complete payload, or throws if progress stops.
     */
    private function writeAll($client, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);

        // Sockets can accept fewer bytes than offered, so keep writing until the whole payload is out.
        while ($offset < $length) {
            $written = fwrite($client, substr($payload, $offset));

            // A failed or zero-byte write cannot advance the offset, so abort instead of looping indefinitely.
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write complete dashboard HTTP response.');
            }

            $offset += $written;
        }
    }
}
