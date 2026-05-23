<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Docs;

/**
 * Fixture for regex comment documentation checks.
 */
final class RegexCommentFixture
{
    /**
     * Check an undocumented regex call.
     *
     * @param string $candidateName Name being checked.
     * @return bool True when the candidate uses the fixture format.
     */
    public function isUndocumentedRegexMatch(string $candidateName): bool
    {
        if ($candidateName === '') {
            return false;
        }

        return preg_match('/^[A-Z][A-Z0-9_]+$/', $candidateName) === 1;
    }

    /**
     * Check a regex call with direct explanatory context.
     *
     * @param string $candidateName Name being checked.
     * @return bool True when the candidate uses the fixture format.
     */
    public function isDocumentedRegexMatch(string $candidateName): bool
    {
        if ($candidateName === '') {
            return false;
        }

        // Match fixture names made from uppercase letters, digits, and underscores.
        return preg_match('/^[A-Z][A-Z0-9_]+$/', $candidateName) === 1;
    }

    /**
     * Check a regex call separated from its context.
     *
     * @param string $candidateName Name being checked.
     * @return bool True when the candidate uses the fixture format.
     */
    public function isSeparatedRegexMatch(string $candidateName): bool
    {
        if ($candidateName === '') {
            return false;
        }

        // Match fixture names made from uppercase letters, digits, and underscores.

        return preg_match('/^[A-Z][A-Z0-9_]+$/', $candidateName) === 1;
    }
}
