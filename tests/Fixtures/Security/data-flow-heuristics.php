<?php

declare(strict_types=1);

function inspectRequestData(): void
{
    $payload = $_POST['payload'] ?? '';
    unserialize($payload);

    header('Location: ' . ($_GET['next'] ?? '/'));
    $redirect = $_GET['redirect'] ?? '/';
    header('Location: ' . $redirect);

    extract($_REQUEST);
    $requestFields = $_REQUEST;
    extract($requestFields);
    compact($_GET);

    md5($_COOKIE['token'] ?? '');
    sha1($_COOKIE['token'] ?? '');
    mcrypt_encrypt('rijndael-128', 'key', 'data', 'ecb');

    rand(1, 100);
    mt_rand(1, 100);
    lcg_value();

    @file_get_contents('/tmp/missing');

    try {
        riskyOperation();
    } catch (RuntimeException $exception) {
    }
}

function riskyOperation(): void
{
    throw new RuntimeException('boom');
}
