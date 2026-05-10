<?php

declare(strict_types=1);

namespace GruffPhp\Command;

final class DashboardHttpResponder
{
    /**
     * @param resource $client
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
