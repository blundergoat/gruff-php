<?php

declare(strict_types=1);

namespace Fixtures\Design\SingleImplementor\InterfaceHierarchy;

/**
 * Parent interface - extended by another internal interface, so it's a
 * polymorphism contract even if no class directly implements it. Must not flag.
 */
interface CacheInterface
{
    public function get(string $key): ?string;
}

/**
 * Child interface extends the parent. The parent is "used" via the child;
 * the child gets one implementor (FileCache) and is also referenced as a
 * type hint elsewhere in this file (CacheClient), so the child must not flag.
 */
interface TaggedCacheInterface extends CacheInterface
{
    public function tagged(string $tag): self;
}

/**
 * Sole implementor of the child interface.
 */
final class FileCache implements TaggedCacheInterface
{
    public function get(string $key): ?string
    {
        return null;
    }

    public function tagged(string $tag): self
    {
        return $this;
    }
}

/**
 * Consumer that type-hints the child interface, proving external usage.
 */
final class CacheClient
{
    /**
     * @param TaggedCacheInterface $cache - The cache to read from.
     */
    public function __construct(private readonly TaggedCacheInterface $cache)
    {
    }

    public function lookup(string $key): ?string
    {
        return $this->cache->get($key);
    }
}
