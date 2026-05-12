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
     * @return PhpUnitConfig|null Parsed config when discovery succeeds.
     */
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
