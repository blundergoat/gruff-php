<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Project;

use SimpleXMLElement;

/**
 * Finds and caches a project's PHPUnit configuration for test-quality rules.
 * The first existing candidate is authoritative; invalid XML in that file ends the search with null.
 * Successful loads and misses are cached per root for this instance, avoiding repeated filesystem reads.
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

            return $this->cache[$key];
        }

        // The first existing candidate is authoritative; malformed XML does not fall through to another filename.
        foreach (self::CANDIDATES as $candidate) {
            $absolute = $key . '/' . $candidate;

            if (!is_file($absolute)) {
                continue;
            }

            $previous = libxml_use_internal_errors(true);
            $loaded   = simplexml_load_file($absolute, SimpleXMLElement::class, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            // The file exists but would not parse, so treat it as no usable config and remember that.
            if (!$loaded instanceof SimpleXMLElement) {


                return $this->cache[$key] = null;
            }


            return $this->cache[$key] = new PhpUnitConfig($absolute, $candidate, $loaded);
        }


        return $this->cache[$key] = null;
    }
}
