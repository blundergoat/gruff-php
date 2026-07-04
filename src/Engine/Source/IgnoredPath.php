<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Source;

/**
 * One skipped path paired with why it was skipped - the report-facing record of an exclusion.
 *
 * Where `IgnoreDecision` answers the yes/no question mid-walk, this is the durable detail kept for the
 * files gruff actually excluded: the display path, the reason category, and the pattern that matched.
 * The summary and reports list these, so a user can see exactly what was left out of the scan and on
 * whose authority - their config, a built-in default, or a .gitignore rule.
 */
final readonly class IgnoredPath
{
    /**
     * Captures one ignored path with the reason category and pattern that excluded it.
     *
     * @param string      $path - Project-relative display path that was ignored.
     * @param string      $source - Reason category: config, default, generated, or gitignore.
     * @param string|null $pattern - The glob, directory token, filename, or git rule that matched; null when the exclusion had no concrete match string.
     */
    public function __construct(
        public string  $path,
        public string  $source,
        public ?string $pattern,
    ) {
    }

    /**
     * Builds an ignored-path detail from a path and the engine decision that excluded it, defaulting a
     * source-less decision to a config exclusion.
     *
     * @param string         $path - Display path that was ignored.
     * @param IgnoreDecision $decision - Engine decision carrying the source and pattern behind the exclusion.
     *
     * @return self - Immutable detail pairing the display path with the resolved source and matching pattern.
     */
    public static function from(string $path, IgnoreDecision $decision): self
    {
        // A decision with no recorded source is treated as a config exclusion, the default ignore origin.
        return new self($path, $decision->source ?? PathIgnoreResolver::SOURCE_CONFIG, $decision->pattern);
    }

    /**
     * Flattens the ignored-path detail into the report row shape, so the skipped-file list serialises cleanly.
     *
     * @return array{path: string, source: string, pattern: string|null} - report row; pattern is null when the exclusion had no concrete match string.
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
