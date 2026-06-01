<?php

declare(strict_types=1);

namespace GruffPhp\Source;

/**
 * A single ignored path enriched with the reason it was excluded.
 */
final readonly class IgnoredPath
{
    /**
     * @param string      $path    Project-relative display path that was ignored.
     * @param string      $source  Reason category: config, default, generated, or gitignore.
     * @param string|null $pattern Matching glob, directory token, filename, or git rule.
     */
    public function __construct(
        public string  $path,
        public string  $source,
        public ?string $pattern,
    ) {
    }

    /**
     * Build an ignored-path detail from a path and the engine decision that excluded it.
     *
     * @param string         $path     Display path that was ignored.
     * @param IgnoreDecision $decision Engine decision carrying the source and pattern.
     *
     * @return self - immutable detail pairing the display path with the resolved source and matching pattern
     */
    public static function from(string $path, IgnoreDecision $decision): self
    {
        // A decision with no recorded source is treated as a config exclusion, the default ignore origin.
        return new self($path, $decision->source ?? PathIgnoreResolver::SOURCE_CONFIG, $decision->pattern);
    }

    /**
     * Serialize the ignored-path detail into the report array shape.
     *
     * @return array{path: string, source: string, pattern: string|null} - report row; pattern is null when the exclusion had no concrete match string
     */
    public function toArray(): array
    {
        // Flatten to the report row shape; pattern stays null when the exclusion had no concrete match string.
        return [
            'path'    => $this->path,
            'source'  => $this->source,
            'pattern' => $this->pattern,
        ];
    }
}
