<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Security;

/**
 * Fixture with safe CORS postures that must not fire the permissive-CORS rule.
 */
final class FrameworkCorsSafeFixture
{
    /**
     * Wildcard origin without credentials (public, credential-free API).
     *
     * @return void
     */
    public function publicCors(): void
    {
        header('Access-Control-Allow-Origin: *');
    }

    /**
     * Specific allowlisted origin with credentials.
     *
     * @return void
     */
    public function scopedCors(): void
    {
        header('Access-Control-Allow-Origin: https://app.example.com');
        header('Access-Control-Allow-Credentials: true');
    }
}
