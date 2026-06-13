<?php

declare(strict_types=1);

namespace Fixtures\Complexity;

final class GuardClauseComplexityFixture
{
    public function flatPayloadValidator(array $payload): ?array
    {
        if (!isset($payload['id'])) { return null; }
        if (!is_string($payload['id'])) { return null; }
        if (!isset($payload['version'])) { return null; }
        if (!is_int($payload['version'])) { return null; }
        if (!isset($payload['mode'])) { return null; }
        if (!is_string($payload['mode'])) { return null; }
        if (!isset($payload['source'])) { return null; }
        if (!is_string($payload['source'])) { return null; }
        if (!isset($payload['messages'])) { return null; }
        if (!is_array($payload['messages'])) { return null; }
        if (!isset($payload['createdAt'])) { return null; }
        if (!is_string($payload['createdAt'])) { return null; }
        if (!isset($payload['checksum'])) { return null; }
        if (!is_string($payload['checksum'])) { return null; }
        if ($payload['checksum'] === '') { return null; }

        return $payload;
    }

    public function telemetryBuilder(array $payload): array
    {
        $fields = [];

        if (isset($payload['id'])) { $fields['id'] = (string) $payload['id']; }
        if (isset($payload['mode'])) { $fields['mode'] = (string) $payload['mode']; }
        if (isset($payload['source'])) { $fields['source'] = (string) $payload['source']; }
        if (isset($payload['durationMs'])) { $fields['durationMs'] = (int) $payload['durationMs']; }
        if (isset($payload['cacheHit'])) { $fields['cacheHit'] = (bool) $payload['cacheHit']; }

        return $fields;
    }

    public function nestedBusinessLogic(array $payload): ?array
    {
        if (isset($payload['id'])) {
            if (is_string($payload['id'])) {
                if (isset($payload['version'])) {
                    if (is_int($payload['version'])) {
                        if (isset($payload['mode'])) {
                            if (is_string($payload['mode'])) {
                                if (isset($payload['source'])) {
                                    if (is_string($payload['source'])) {
                                        if (isset($payload['messages'])) {
                                            if (is_array($payload['messages'])) {
                                                if (isset($payload['createdAt'])) {
                                                    if (is_string($payload['createdAt'])) {
                                                        if (isset($payload['checksum'])) {
                                                            if (is_string($payload['checksum'])) {
                                                                if ($payload['checksum'] !== '') {
                                                                    return $payload;
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    public function mixedResponsibilities(array $payload): array
    {
        try {
            foreach ($this->fetchRows($payload) as $row) {
                if (!$this->authorised($row)) {
                    $this->logDenied($row);
                    continue;
                }

                if (($row['active'] ?? false) === true) {
                    $payload['rows'][] = $this->transform($row);
                }
            }
        } catch (\RuntimeException $exception) {
            $this->logError($exception);
        }

        return $payload;
    }

    private function fetchRows(array $payload): array
    {
        return $payload['rows'] ?? [];
    }

    private function authorised(array $row): bool
    {
        return ($row['role'] ?? null) === 'admin';
    }

    private function logDenied(array $row): void
    {
        unset($row);
    }

    private function transform(array $row): array
    {
        return $row;
    }

    private function logError(\RuntimeException $exception): void
    {
        unset($exception);
    }
}
