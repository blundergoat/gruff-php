<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use LogicException;

final readonly class RuleSettings
{
    /**
     * @param array<string, int|float> $thresholds
     * @param array<string, mixed> $options
     */
    public function __construct(
        public bool $enabled,
        public array $thresholds,
        public array $options = [],
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

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function option(string $name): mixed
    {
        if (!array_key_exists($name, $this->options)) {
            throw new LogicException(sprintf('Missing option "%s".', $name));
        }

        return $this->options[$name];
    }

    /**
     * @return list<string>
     */
    public function stringListOption(string $name): array
    {
        $value = $this->options[$name] ?? [];

        if (!is_array($value)) {
            throw new LogicException(sprintf('Option "%s" must be an array of strings.', $name));
        }

        $result = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new LogicException(sprintf('Option "%s" must contain only strings.', $name));
            }

            $result[] = $item;
        }

        return $result;
    }
}
