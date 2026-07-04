<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Project;

use SimpleXMLElement;

/**
 * Finds and parses a project's PHPUnit config once, so test-quality rules can inspect how tests run.
 *
 * Several test-quality rules need the project's PHPUnit configuration. This locates the first supported
 * config file under the project root, parses its XML, and memoises the result - including a "no config
 * here" answer - so repeated lookups during a run never re-hit the disk. A present-but-broken config is
 * treated as no usable config rather than being allowed to crash the run.
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
     * Returns the project's parsed PHPUnit config, or null when there is none, caching either answer so
     * a run resolves it just once per root.
     *
     * @param string $projectRoot - Project root where PHPUnit config files are searched.
     *
     * @return PhpUnitConfig|null - The parsed config; null when no candidate file exists or the one found could not be parsed.
     */
    public function discover(string $projectRoot): ?PhpUnitConfig
    {
        $key = rtrim($projectRoot, '/');
        // A cached answer stands, including a cached null - "no config here" is a real result, not a reason to re-scan.
        if (array_key_exists($key, $this->cache)) {
            // Memoised result; a cached null is a real answer (no config here) and must not re-trigger a disk scan.
            return $this->cache[$key];
        }

        // Try each supported config filename in order, taking the first that both exists and parses.
        foreach (self::CANDIDATES as $candidate) {
            $absolute = $key . '/' . $candidate;
            // This candidate name is not present here, so move on to the next.
            if (!is_file($absolute)) {
                continue;
            }

            $previous = libxml_use_internal_errors(true);
            $loaded   = simplexml_load_file($absolute, SimpleXMLElement::class, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            // The file exists but would not parse, so treat it as no usable config and remember that.
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
