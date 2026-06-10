<?php

declare(strict_types=1);

define('JETPACK_DIR', __DIR__);

function attackShapedIncludePaths(string $base, string $name): void
{
    include $_GET['page'];
    include $base . $_REQUEST['m'] . '.php';

    $laundered = $_POST['x'];
    include $laundered;

    $template = __DIR__ . '/default.php';
    $template = $_GET['t'];
    include $template;

    include strtolower($name) . '.php';
    include conf . '.php';
}

function poisonIncludePath(string &$path): void
{
    $path = $_GET['template'];
}

function byReferenceIncludePathOverwrite(): void
{
    $template = __DIR__ . '/default.php';
    poisonIncludePath($template);
    include $template;
}

function fixedShapeIncludePaths(): void
{
    require_once ABSPATH . 'wp-admin/x.php';
    require_once JETPACK_DIR . '/y.php';

    $fixedDir = __DIR__ . '/inc/';
    require $fixedDir . 'z.php';

    $laterCall = __DIR__ . '/later.php';
    require $laterCall;
    poisonIncludePath($laterCall);
}
