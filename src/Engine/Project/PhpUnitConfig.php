<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Project;

use SimpleXMLElement;

/**
 * A project's loaded PHPUnit configuration - the parsed XML plus where it was found.
 *
 * Some test-quality rules need to know how the project runs its tests, so gruff finds the PHPUnit
 * config and parses it once into this object. Rules read the XML root from here (for example to check
 * bootstrap or coverage settings), and the display path lets any finding point the user back at the
 * config file.
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
