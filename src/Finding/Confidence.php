<?php

declare(strict_types=1);

namespace GruffPhp\Finding;

enum Confidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
