<?php

declare(strict_types=1);

namespace GruffPhp\Finding;

enum Severity: string
{
    case Advisory = 'advisory';
    case Warning = 'warning';
    case Error = 'error';
}
