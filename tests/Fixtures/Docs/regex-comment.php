<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Docs;

/**
 * Supplies call shapes the parser feeds to regex-comment tests so users see retained and suppressed findings.
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

    /**
     * Check a multiline condition whose statement comment explains the user-facing branch.
     *
     * @param string $message - Candidate message being classified.
     *
     * @return bool - True when the message requests a weekday route.
     */
    public function hasAdjacentMultilineCondition(string $message): bool
    {
        // A weekday prompt opens that exact day's schedule instead of broad availability.
        if (
            preg_match('/\b(?:monday|tuesday)\b/i', $message) === 1
        ) {
            return true;
        }

        return false;
    }

    /**
     * Fold copied spacing through a wrapped assignment documented at statement level.
     *
     * @param string $message - User text whose visible spacing is being normalised.
     *
     * @return string - Normalised text, or an empty string when replacement yields no text.
     */
    public function normaliseWrappedAssignment(string $message): string
    {
        // Collapse copied whitespace so the user's clinic wording matches the visible label.
        $normalised = rtrim(
            (string) preg_replace('/\s+/', ' ', trim($message)),
            ".? \t\n\r\0\x0B",
        );

        // An empty transformation remains empty rather than manufacturing visible text.
        if ($normalised === '') {
            return '';
        }

        return $normalised;
    }

    /**
     * Check either explicitly documented route phrase in one shared condition.
     *
     * @param string $message - User text being routed.
     *
     * @return bool - True when either supported route phrase appears.
     */
    public function hasSharedConditionComment(string $message): bool
    {
        // Either explicit phrase routes the user to the same schedule view.
        if (
            preg_match('/today schedule/i', $message) === 1
            || preg_match('/weekday schedule/i', $message) === 1
        ) {
            return true;
        }

        return false;
    }

    /**
     * Keep preparation context attached only to the statement it describes.
     *
     * @param string $message - User text prepared before the later check.
     *
     * @return bool - True when the prepared text has the retained shape.
     */
    public function hasPreviousStatementCommentOnly(string $message): bool
    {
        // Remove outside spacing before the separate shape check below.
        $preparedMessage = trim($message);

        return preg_match('/^[a-z ]+$/i', $preparedMessage) === 1;
    }

    /**
     * Keep a trailing preparation comment from documenting the following condition.
     *
     * @param string $message - User text prepared before the later check.
     *
     * @return bool - True when the prepared text has the retained shape.
     */
    public function hasTrailingPreviousStatementComment(string $message): bool
    {
        $preparedMessage = trim($message); // Explains preparation only.
        if (
            preg_match('/^[a-z ]+$/i', $preparedMessage) === 1
        ) {
            return true;
        }

        return false;
    }

    /**
     * Build a nested candidate validator without lending outer context to its body.
     *
     * @param string $candidateName - Candidate passed into the nested validator.
     *
     * @return bool - True when the nested validator accepts the candidate.
     */
    public function hasNestedCallableWithoutInnerComment(string $candidateName): bool
    {
        // Build the callback as a nested contract, not as documentation for its internals.
        $validator = static function (string $candidateName): bool {
            return preg_match('/^[A-Z][A-Z0-9_]+$/', $candidateName) === 1;
        };

        return $validator($candidateName);
    }

    /**
     * Return lower-case, whitespace-folded text for repeat comparison.
     *
     * @param string $message - User text being made comparable across spacing differences.
     *
     * @return string - Folded text, or an empty string when replacement yields no text.
     */
    public function foldWhitespaceForRepeatComparison(string $message): string
    {
        $foldedMessage = (string) preg_replace('/\s+/', ' ', strtolower(trim($message)));

        // An empty fold remains empty so callers can distinguish it from visible comparison text.
        if ($foldedMessage === '') {
            return '';
        }

        return $foldedMessage;
    }

    /**
     * Return whitespace-folded text while naming each transformation input by its semantic role.
     *
     * @param string $message - User text whose repeated whitespace is collapsed.
     *
     * @return string - Folded text, or an empty string when replacement yields no text.
     */
    public function foldWhitespaceWithReorderedNamedArguments(string $message): string
    {
        $foldedMessage = (string) preg_replace(
            subject: $message,
            replacement: ' ',
            pattern: '/\s+/',
        );

        // An empty fold remains empty so callers can distinguish it from visible comparison text.
        if ($foldedMessage === '') {
            return '';
        }

        return $foldedMessage;
    }

    /**
     * Describe whitespace-folding intent around a call whose named values do not perform that fold.
     *
     * @param string $message - User text passed through the misleading replacement shape.
     *
     * @return string - Replaced text, or an empty string when replacement yields no text.
     */
    public function misleadingNamedWhitespaceFold(string $message): string
    {
        $replacedMessage = (string) preg_replace(
            replacement: '/\s+/',
            pattern: ' ',
            subject: $message,
        );

        // An empty replacement remains empty rather than becoming synthetic visible text.
        if ($replacedMessage === '') {
            return '';
        }

        return $replacedMessage;
    }

    /**
     * Apply the fixture patterns before deciding whether text can be indexed.
     *
     * @param string $candidateName - Candidate text being classified and cleaned.
     *
     * @return bool - True when the text has the expected shape and a non-empty cleaned value.
     */
    public function hasBroadPatternsContract(string $candidateName): bool
    {
        $matchesIdentifier = preg_match('/^[A-Z][A-Z0-9_-]+$/', $candidateName) === 1;
        $withoutDashes     = (string) preg_replace('/-+/', '', $candidateName);

        return $matchesIdentifier && $withoutDashes !== '';
    }

    /**
     * Describe a spacing contract around a replacement that does not fold whitespace.
     *
     * @param string $message - User text passed through the unrelated replacement.
     *
     * @return string - Replaced text, or an empty string when replacement yields no text.
     */
    public function unrelatedReplacementUnderWhitespaceContract(string $message): string
    {
        $replacedMessage = (string) preg_replace('/clinic/', 'practice', $message);

        // An empty replacement remains empty rather than becoming a synthetic user value.
        if ($replacedMessage === '') {
            return '';
        }

        return $replacedMessage;
    }

    /**
     * Return safe, valid display text without claiming how the transformation works.
     *
     * @param string $message - User text being cleaned for display.
     *
     * @return string - Display text, or an empty string when replacement yields no text.
     */
    public function safelyValidateText(string $message): string
    {
        $displayText = (string) preg_replace('/[^a-z ]/i', '', $message);

        // An empty cleaned value remains empty so the caller can reject it explicitly.
        if ($displayText === '') {
            return '';
        }

        return $displayText;
    }
}
