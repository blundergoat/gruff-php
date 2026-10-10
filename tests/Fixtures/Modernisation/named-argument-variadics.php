<?php
// PHP's variadic built-ins cannot name their extra values, and a dynamic callee's declaration cannot be read, so none
// of these calls may be told to use named arguments. The line ending in "reported" still must be.

function fiveUserlandParameters(int $first, int $second, int $third, int $fourth, int $fifth): int
{
    return $first + $second + $third + $fourth + $fifth;
}

function formatVariadics(string $a, string $b, string $c, string $d, string $e, array $rows, callable $handler): void
{
    $line = sprintf('%s %s %s %s %s', $a, $b, $c, $d, $e);
    printf('%s %s %s %s', $a, $b, $c, $d);
    $packed = pack('nvc*', 0x1234, 0x5678, 65, 66, 67);
    $merged = array_merge($rows, $rows, $rows, $rows, $rows);
    $names = compact('a', 'b', 'c', 'd', 'e');
    $handler(1, 2, 3, 4, 5);
    fiveUserlandParameters(1, 2, 3, 4, 5); // reported
    // A namespaced function that shares a built-in's short name is userland, so its parameters have names.
    \App\sprintf('%s %s %s %s', $a, $b, $c, $d); // reported
}
