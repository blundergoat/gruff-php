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
    public function inspect(\PDO $pdo, \CurlHandle $curl, bool $ok): void
    {
        safe_exec('date');
        \App\SecurityFixtures\exec('namespaced wrapper');
        assert($ok);
        unserialize('a:0:{}');

        include 'static-template.php';
        $pdo->query('SELECT * FROM users WHERE id = ?');
        header('Location: /dashboard');

        random_int(1, 10);
        random_bytes(16);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    }
}
