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
        public string $path,
        public string $source,
        public ?string $pattern,
    ) {
    }

    /**
     * Build an ignored-path detail from a path and the engine decision that excluded it.
     *
     * @param string         $path     Display path that was ignored.
     * @param IgnoreDecision $decision Engine decision carrying the source and pattern.
     * @return self Ignored-path detail.
     */
    public static function from(string $path, IgnoreDecision $decision): self
    {
        return new self($path, $decision->source ?? PathIgnoreResolver::SOURCE_CONFIG, $decision->pattern);
    }

    /**
     * Serialize the ignored-path detail into the report array shape.
     *
     * @return array{path: string, source: string, pattern: string|null}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'source' => $this->source,
            'pattern' => $this->pattern,
        ];
    }
}
