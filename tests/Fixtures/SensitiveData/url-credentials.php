<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\SensitiveData;

/**
 * Fixture exercising the URL-embedded-credentials rule.
 */
final class UrlCredentialsFixture
{
    /**
     * HTTPS URL with an inline credential (unsafe).
     *
     * @return string
     */
    public function internalApi(): string
    {
        return 'https://svcuser:s3cr3tValue@api.internal.example.com/v1';
    }

    /**
     * HTTP URL with an inline credential (unsafe).
     *
     * @return string
     */
    public function deployHook(): string
    {
        return 'http://deploy:Tok3nXyZ9@hooks.internal.example.com/push';
    }

    /**
     * Public URL without credentials (safe).
     *
     * @return string
     */
    public function publicApi(): string
    {
        return 'https://api.example.com/v1';
    }

    /**
     * URL with a placeholder credential (safe — dummy value).
     *
     * @return string
     */
    public function placeholderCred(): string
    {
        return 'https://user:changeme@example.com';
    }

    /**
     * Database URL credential (handled by the database-url rule, not this one).
     *
     * @return string
     */
    public function databaseUrl(): string
    {
        return 'mysql://dbuser:dbpassval@localhost:3306/app';
    }
}
