<?php

declare(strict_types=1);

// API-key shapes inside line comments should be skipped by opt-in pattern rules.
// Stripe: sk_live_51N7uQbP0JZ6rT9vL3mK8sX2y
// GitHub: ghp_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789
// Anthropic: sk-ant-api03-uQ7vR2mN5xP8zL1kC4bH9sT6wY3aD0fG

/*
 * Block comment shapes that look like credentials.
 * AWS key: AKIAZ9Y8X7W6V5U4T3R2
 * JWT: eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.sflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c
 * Database URL: mysql://appuser:rN7pQ4sV9xY2zA5b@db.internal/app
 * Reach out to firstname.lastname@personal-mail.test for assistance.
 */

/**
 * Docblock example with API_TOKEN=rN7pQ4sV9xY2zA5bC8dG and a high-entropy literal
 * "M7qP2vL9xZ4aB8nC3dF6gH1jK5mN0rS2tV9wY4zQ" embedded in the prose.
 *
 * Example patient medicare identifier: 2950 03775 3 (synthetic).
 */
final class CommentSkippedFixture
{
}

// Private key headers in comments are still leaked material and must fire.
// -----BEGIN RSA PRIVATE KEY-----
// synthetic fixture body redacted
// -----END RSA PRIVATE KEY-----
