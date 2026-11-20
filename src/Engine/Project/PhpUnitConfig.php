<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Project;

use SimpleXMLElement;

/**
 * Carries a project's parsed PHPUnit XML and the configuration file's paths.
 * Test-quality rules read the root element to inspect settings such as coverage and strictness flags.
 * The display path lets findings identify the configuration file.
 */
final readonly class PhpUnitConfig
{
    /**
     * Bundles a discovered PHPUnit config file with its already-parsed XML root.
     *
     * @param string           $absolutePath - Absolute path to the PHPUnit config file on disk.
     * @param string           $displayPath - Project-relative path shown to the user in findings about the config.
     * @param SimpleXMLElement $root - Parsed PHPUnit XML root element that rules inspect.
     */
    public function __construct(
        public string $absolutePath,
        public string $displayPath,
        public SimpleXMLElement $root,
    ) {
    }
}
