<?php

declare(strict_types=1);

namespace Fixtures\Security\CompoundAssignmentTaint;

function taintedHeader(): void
{
    $value = 'X-Trace: ';
    $value .= $_GET['trace'];
    header($value);
}

function taintedEcho(): void
{
    $html = '<p>';
    $html .= $_GET['name'];
    echo $html;
}

function taintedInclude(): void
{
    $path = __DIR__ . '/pages/';
    $path .= $_GET['page'];
    include $path;
}

function taintedCommand(): string|false
{
    $command = 'convert ';
    $command .= $_GET['file'];

    return `{$command}`;
}

function taintedUrl(): void
{
    $url = 'https://api.example.test/?q=';
    $url .= $_GET['q'];
    curl_init($url);
}

function cleanOverwrite(): void
{
    $value = $_GET['trace'];
    $value = 'X-Trace: fixed';
    header($value);
}

function cleanConcatOnCleanBase(): void
{
    $value = 'X-Trace: ';
    $value .= 'static-suffix';
    header($value);
}
