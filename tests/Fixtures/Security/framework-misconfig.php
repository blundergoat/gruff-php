<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Security;

/**
 * Fixture exercising permissive-CORS (wildcard + credentials) and debug-mode rules.
 */
final class FrameworkMisconfigFixture
{
    /**
     * Send a wildcard CORS origin alongside credentials (unsafe).
     *
     * @return void
     */
    public function unsafeCors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Credentials: true');
    }

    /**
     * Force display_errors on (unsafe).
     *
     * @return void
     */
    public function enableDebug(): void
    {
        ini_set('display_errors', '1');
    }

    /**
     * Force display_startup_errors on (unsafe).
     *
     * @return void
     */
    public function enableStartupDebug(): void
    {
        ini_set('display_startup_errors', 'On');
    }

    /**
     * Disable display_errors (safe).
     *
     * @return void
     */
    public function disableDebug(): void
    {
        ini_set('display_errors', '0');
    }

    /**
     * Set an unrelated ini directive (safe).
     *
     * @return void
     */
    public function unrelatedIniSet(): void
    {
        ini_set('memory_limit', '256M');
    }
}
