<?php

declare(strict_types=1);

function cumulativeSecurityPatterns(PDO $pdo, CurlHandle $curl): void
{
    exec($_GET['cmd'] ?? 'date');
    unserialize($_POST['payload'] ?? '');
    md5('token');
    include $_GET['template'];
    $pdo->query('SELECT * FROM users WHERE id = ' . ($_GET['id'] ?? '0'));
    header('Location: ' . ($_GET['next'] ?? '/'));
    @file_get_contents('/tmp/missing');

    try {
        riskyCumulativeOperation();
    } catch (RuntimeException $exception) {
    }

    extract($_REQUEST);
    rand(1, 100);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
}

function riskyCumulativeOperation(): void
{
    throw new RuntimeException('boom');
}
