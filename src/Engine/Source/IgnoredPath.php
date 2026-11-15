<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

/**
 * Records an excluded path with its reason category and matching pattern for reports.
 * `IgnoreDecision` supplies exclusion details; this record associates them with the display path.
 * A null pattern means no concrete match string was recorded.
 */
final readonly class IgnoredPath
{
    /**
     * Captures one ignored path with the reason category and pattern that excluded it.
     *
     * @param string      $path - Project-relative display path that was ignored.
     * @param string      $source - Reason category: config, default, generated, or gitignore.
     * @param string|null $pattern - Matched glob, directory token, filename, or Git rule; null when no concrete match string was recorded.
     */
    public function __construct(
        public string  $path,
        public string  $source,
        public ?string $pattern,
    ) {
    }

    /**
     * Builds an ignored-path detail from a path and the engine decision that excluded it, defaulting a source-less decision to a config exclusion.
     *
     * @param string         $path - Display path that was ignored.
     * @param IgnoreDecision $decision - Engine decision carrying the source and pattern behind the exclusion.
     *
     * @return self - Detail pairing the display path with the resolved source and matching pattern.
     */
    public static function from(string $path, IgnoreDecision $decision): self
    {

        return new self($path, $decision->source ?? PathIgnoreResolver::SOURCE_CONFIG, $decision->pattern);
    }

    /**
     * Flattens the ignored-path detail into the report row shape, so the skipped-file list serialises cleanly.
     *
     * @return array{path: string, source: string, pattern: string|null} - Report row; a null pattern means no concrete match string.
     */
    public function toArray(): array
    {
        return [
            'path'    => $this->path,
            'source'  => $this->source,
            'pattern' => $this->pattern,
        ];
    }
}
