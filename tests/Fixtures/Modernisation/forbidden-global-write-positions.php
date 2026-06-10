<?php

declare(strict_types=1);

namespace Fixtures\Modernisation;

function readsAndWritesSuperglobals(): string
{
    // Positive: plain read of a superglobal outside a controller boundary.
    $requestId = $_GET['id'] ?? '0';

    // Positive: compound assignment reads the current value before writing it back.
    $_GET['token'] .= '-suffix';

    // Positive: the dimension expression inside a write target is still a read ($_POST), while the $_GET base is a write.
    $_GET[$_POST['key']] = 'mapped';

    // Negative: direct write into a superglobal mutates state without reading it.
    $_GET['filter'] = 'active';

    // Negative: nested dimension writes are still writes at the superglobal root.
    $_SESSION['cart']['items'] = [];

    // Negative: unset removes a key without reading it.
    unset($_POST['csrf']);

    return $requestId;
}
