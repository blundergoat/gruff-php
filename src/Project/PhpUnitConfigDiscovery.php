<?php

declare(strict_types=1);

namespace GruffPhp\Project;

use SimpleXMLElement;

final class PhpUnitConfigDiscovery
{
    private const CANDIDATES = ['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'];

    /** @var array<string, ?PhpUnitConfig> */
    private array $cache = [];

    public function discover(string $projectRoot): ?PhpUnitConfig
    {
        $key = rtrim($projectRoot, '/');
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        foreach (self::CANDIDATES as $candidate) {
            $absolute = $key . '/' . $candidate;
            if (!is_file($absolute)) {
                continue;
            }

            $previous = libxml_use_internal_errors(true);
            $loaded = simplexml_load_file($absolute, SimpleXMLElement::class, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if (!$loaded instanceof SimpleXMLElement) {
                return $this->cache[$key] = null;
            }

            return $this->cache[$key] = new PhpUnitConfig($absolute, $candidate, $loaded);
        }

        return $this->cache[$key] = null;
    }
}
