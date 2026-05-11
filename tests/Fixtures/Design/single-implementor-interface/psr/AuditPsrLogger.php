<?php

declare(strict_types=1);

namespace Fixtures\Design\SingleImplementor\Psr;

use Psr\Log\LoggerInterface;

/**
 * Internal interface that extends a PSR contract. Must not flag.
 */
interface AuditLoggerInterface extends LoggerInterface
{
    public function flush(): void;
}

/**
 * Single implementor - rule must not flag because the interface extends PSR.
 */
final class AuditPsrLogger implements AuditLoggerInterface
{
    public function flush(): void
    {
    }

    /**
     * @param mixed[] $context
     */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
    }

    /**
     * @param mixed[] $context
     */
    public function alert(string|\Stringable $message, array $context = []): void
    {
    }

    /**
     * @param mixed[] $context
     */
    public function critical(string|\Stringable $message, array $context = []): void
    {
    }

    /**
     * @param mixed[] $context
     */
    public function error(string|\Stringable $message, array $context = []): void
    {
    }

    /**
     * @param mixed[] $context
     */
    public function warning(string|\Stringable $message, array $context = []): void
    {
    }

    /**
     * @param mixed[] $context
     */
    public function notice(string|\Stringable $message, array $context = []): void
    {
    }

    /**
     * @param mixed[] $context
     */
    public function info(string|\Stringable $message, array $context = []): void
    {
    }

    /**
     * @param mixed[] $context
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
    }

    /**
     * @param mixed[] $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
    }
}
