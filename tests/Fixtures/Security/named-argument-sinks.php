<?php
// Each global sink appears twice: positionally, then written entirely with named arguments.
// Both lines must flag, because PHP resolves the two calls to the same argument.

function exerciseNamedHeaderSink(): void
{
    header($_GET['location']);
    header(header: $_GET['location']);
}

function exerciseNamedUnserializeSink(): void
{
    unserialize($_GET['payload']);
    unserialize(data: $_GET['payload']);
}

function exerciseNamedExtractSink(): void
{
    extract($_GET);
    extract(array: $_GET);
}

function exerciseNamedDebugSink(): void
{
    ini_set('display_errors', '1');
    ini_set(option: 'display_errors', value: '1');
}

function exerciseNamedSslSink(mixed $handle): void
{
    curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt(handle: $handle, option: CURLOPT_SSL_VERIFYPEER, value: false);
}

function exerciseNamedPathSink(): void
{
    file_get_contents($_GET['path']);
    file_get_contents(filename: $_GET['path']);
}

function exerciseNamedXmlSink(): void
{
    simplexml_load_string($_GET['xml']);
    simplexml_load_string(data: $_GET['xml']);
}

function exerciseSafeNamedSinks(): void
{
    header('X-Frame-Options: DENY');
    unserialize(data: 'a:0:{}');
}

// A guard passed by name is still a guard. Resolving the sink argument without also resolving the
// guard would report both of these, which is a false positive on a call that set the flag.
function exerciseGuardedNamedXmlSink(): void
{
    simplexml_load_string($_GET['xml'], null, LIBXML_NONET);
    simplexml_load_string(data: $_GET['xml'], options: LIBXML_NONET);
}
