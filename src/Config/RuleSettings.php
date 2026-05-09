<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use LogicException;

final readonly class RuleSettings
{
    /**
     * @param array<string, int|float> $thresholds
     */
    public function __construct(
        public bool $enabled,
        public array $thresholds,
    ) {
    }

    public function numericThreshold(string $name): int|float
    {
        $value = $this->thresholds[$name] ?? null;

        if (!is_int($value) && !is_float($value)) {
            throw new LogicException(sprintf('Missing numeric threshold "%s".', $name));
        }

        return $value;
    }
}
