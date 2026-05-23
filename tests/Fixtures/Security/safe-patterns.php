<?php

declare(strict_types=1);

namespace App\SecurityFixtures;

function exec(string $command): string
{
    return $command;
}

function safe_exec(string $command): string
{
    return $command;
}

final class SafePatterns
{
    public function inspect(\PDO $pdo, \CurlHandle $curl, \ZipArchive $archive, object $client, object $logger, bool $ok): void
    {
        safe_exec('date');
        \App\SecurityFixtures\exec('namespaced wrapper');
        new \Symfony\Component\Process\Process(['git', 'status']);
        assert($ok);
        unserialize('a:0:{}');

        $path = $_GET['file'] ?? 'report.txt';
        $path = __DIR__ . '/static-template.php';
        file_get_contents($path);
        include 'static-template.php';
        $pdo->query('SELECT * FROM users WHERE id = ?');
        $client->request('GET', 'https://api.example.test/status');
        header('Location: /dashboard');
        $redirect = $_GET['next'] ?? '/';
        $redirect = '/dashboard';
        header('Location: ' . $redirect);
        $fields = ['safe' => 'value'];
        extract($fields);
        $xml = $_GET['xml'] ?? '<root/>';
        simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        $archive->extractTo(__DIR__ . '/extract');
        $logger->info('Completed static fixture run.', ['status' => 'ok']);

        random_int(1, 10);
        random_bytes(16);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    }
}
