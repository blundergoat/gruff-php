<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * Represents how reliable a rule's heuristic signal is.
 */
enum Confidence: string
{
    /**
     * Heuristic signal with meaningful false-positive risk.
     */
    case Low = 'low';

    /**
     * Heuristic signal with moderate reliability.
     */
    case Medium = 'medium';

    /**
     * Heuristic signal expected to be reliable in normal use.
     */
    case High = 'high';
}
