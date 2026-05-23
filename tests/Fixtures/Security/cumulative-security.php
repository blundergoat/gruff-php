<?php

declare(strict_types=1);

function cumulativeSecurityPatterns(PDO $pdo, CurlHandle $curl, ZipArchive $archive): void
{
    exec($_GET['cmd'] ?? 'date');
    new Symfony\Component\Process\Process(['git', 'show', $_GET['ref'] ?? 'HEAD']);
    file_get_contents('/srv/reports/' . ($_GET['file'] ?? 'index.txt'));
    curl_init('https://api.example.test/' . ($_GET['tenant'] ?? 'default'));
    simplexml_load_string($_POST['xml'] ?? '<root/>');
    $archive->extractTo($_GET['dest'] ?? '/tmp/gruff-safe');
    error_log($_POST['password'] ?? '<redacted>');
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
