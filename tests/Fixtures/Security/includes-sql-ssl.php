<?php

declare(strict_types=1);

function inspectRuntimeBoundaries(PDO $pdo, CurlHandle $curl): void
{
    $page = $_GET['page'] ?? 'home.php';
    include $page;
    require_once __DIR__ . '/' . $page;

    $id = $_GET['id'] ?? '0';
    $pdo->query('SELECT * FROM users WHERE id = ' . $id);
    $pdo->exec("DELETE FROM sessions WHERE user_id = {$id}");
    Database::select('SELECT * FROM audit WHERE actor = ' . $id);

    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt_array($curl, [
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
}

final class Database
{
    public static function select(string $query): array
    {
        return [$query];
    }
}
