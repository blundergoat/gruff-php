<?php

declare(strict_types=1);

namespace GruffPhp\Project;

use SimpleXMLElement;

/**
 * Locates and parses PHPUnit configuration files for test-quality rules.
 */
final class PhpUnitConfigDiscovery
{
    /**
     * Supported PHPUnit configuration file names in discovery order.
     */
    private const CANDIDATES = ['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'];

    /** @var array<string, ?PhpUnitConfig> */
    private array $cache = [];

    /**
     * Find and parse the first supported PHPUnit config file under a project root.
     *
     * @param string $projectRoot Project root where PHPUnit config files are searched.
     * @return PhpUnitConfig|null Parsed config when discovery succeeds.
     */
    public function discover(string $projectRoot): ?PhpUnitConfig
    {
        $key = rtrim($projectRoot, '/');
        if (array_key_exists($key, $this->cache)) {
            // Memoised result; a cached null is a real answer (no config here) and must not re-trigger a disk scan.
            return $this->cache[$key];
        }

        foreach (self::CANDIDATES as $candidate) {
            $absolute = $key . '/' . $candidate;
            if (!is_file($absolute)) {
                continue;
            }

            $previous = libxml_use_internal_errors(true);
            $loaded   = simplexml_load_file($absolute, SimpleXMLElement::class, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if (!$loaded instanceof SimpleXMLElement) {
                // A present-but-unparseable config counts as no usable config; cache the miss so a
                // malformed file is not re-read on every lookup for this root.
                return $this->cache[$key] = null;
            }

            // First candidate that exists and parses wins; remaining candidate names are ignored by design.
            return $this->cache[$key] = new PhpUnitConfig($absolute, $candidate, $loaded);
        }

        // No candidate file under this root; cache the negative result for subsequent lookups.
        return $this->cache[$key] = null;
    }
}
