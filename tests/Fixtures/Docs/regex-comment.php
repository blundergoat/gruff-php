<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Docs;

/**
 * Fixture for regex comment documentation checks.
 */
final class RegexCommentFixture
{
    /**
     * Validate the candidate against the fixture identifier shape.
     *
     * @param string $candidateName - Name being checked.
     *
     * @return bool - True when the candidate uses the fixture format.
     */
    public function isUndocumentedRegexMatch(string $candidateName): bool
    {
        if ($candidateName === '') {
            return false;
        }

        return preg_match('/^[A-Z][A-Z0-9_]+$/', $candidateName) === 1;
    }

    /**
     * Check the candidate with direct explanatory context.
     *
     * @param string $candidateName - Name being checked.
     *
     * @return bool - True when the candidate uses the fixture format.
     */
    public function isDocumentedRegexMatch(string $candidateName): bool
    {
        if ($candidateName === '') {
            return false;
        }

        // Accept fixture names made from uppercase letters, digits, and underscores.
        return preg_match('/^[A-Z][A-Z0-9_]+$/', $candidateName) === 1;
    }

    /**
     * Confirm the candidate uses the fixture identifier shape after blank-line separation.
     *
     * @param string $candidateName - Name being checked.
     *
     * @return bool - True when the candidate uses the fixture format.
     */
    public function isSeparatedRegexMatch(string $candidateName): bool
    {
        if ($candidateName === '') {
            return false;
        }

        // Accept fixture names made from uppercase letters, digits, and underscores.

        return preg_match('/^[A-Z][A-Z0-9_]+$/', $candidateName) === 1;
    }

    /**
     * Apply the fixture identifier regex described in this docblock; no inline comment is needed
     * because the function-level docblock already explains the pattern's purpose.
     *
     * @param string $candidateName - Name being checked.
     *
     * @return bool - True when the candidate uses the fixture format.
     */
    public function exemptByFunctionDocKeyword(string $candidateName): bool
    {
        if ($candidateName === '') {
            return false;
        }

        return preg_match('/^[A-Z][A-Z0-9_]+$/', $candidateName) === 1;
    }

    /**
     * Classify the candidate by the labelled match arm; the string label acts as the call's
     * explanation, so the per-call inline comment is not required.
     *
     * @param string $candidateName - Name being checked.
     *
     * @return string|null - Label describing the matched shape, or null when no arm matches.
     */
    public function exemptByMatchArmLabel(string $candidateName): ?string
    {
        return match (true) {
            preg_match('/^[A-Z][A-Z0-9_]+$/', $candidateName) === 1 => 'screaming-snake',
            preg_match('/^[a-z][a-zA-Z0-9]+$/', $candidateName) === 1 => 'camel',
            default => null,
        };
    }

    /**
     * Match the candidate name to a fixture key in ordinary English usage.
     * The word "match" here is incidental prose and must not exempt the call below.
     *
     * @param string $candidateName - Name being checked.
     *
     * @return bool - True when the candidate uses the fixture format.
     */
    public function matchTheRouteUncommentedRegex(string $candidateName): bool
    {
        if ($candidateName === '') {
            return false;
        }

        return preg_match('/^[A-Z][A-Z0-9_]+$/', $candidateName) === 1;
    }
}
