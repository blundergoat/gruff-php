<?php

declare(strict_types=1);

namespace GruffPhp\Rules\SensitiveData;

/**
 * Keeps recognizable public constants out of the user's entropy warnings.
 *
 * The detector calls these checks before scoring a complete literal.
 * Readable names and public formats cannot hide an unrelated opaque suffix.
 */
final class EntropyPublicShape
{
    /** @var list<string> Complete public alphabets; appended text cannot inherit their exception. */
    private const ALPHABETS = [
        'abcdefghijklmnopqrstuvwxyz0123456789',
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
        '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
        '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_',
        'abcdefghijklmnopqrstuvwxyz0123456789-_',
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=',
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_',
        'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRTUVWXY23456789',
        'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
        '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-_',
    ];

    /**
     * Checks a complete literal before the scanner raises an entropy warning.
     *
     * @param string $candidate - Whole literal content without quotes; empty content matches no exception.
     * @return bool - Whether scoring may be skipped; an accepted shape does not prove the value is public.
     */
    public static function accepts(string $candidate): bool
    {
        return in_array($candidate, self::ALPHABETS, true)
            // Only this complete portal route with fixed navigation flags establishes public application metadata.
            || preg_match('%^https://entra\.microsoft\.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/Credentials/appId/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/isMSAApp~/false\?Microsoft_AAD_IAM_legacyAADRedirect=true$%D', $candidate) === 1
            // These nine complete service IDs identify signature implementations; prefixes and opaque tails stay scannable.
            || preg_match('/^security\.access_token_handler\.oidc\.signature\.(?:ES|RS|PS)(?:256|384|512)$/D', $candidate) === 1
            // A complete public revision reference must contain no authentication or extra URL components.
            || preg_match('~^https://github\.com/[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?/[A-Za-z0-9][A-Za-z0-9._-]{0,99}/commit/[0-9a-f]{40}$~D', $candidate) === 1
            // Match only a whole OAuth client ID or a versioned compiler artifact filename.
            || preg_match('/^(?:[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com|soljson-v[0-9]+\.[0-9]+\.[0-9]+\+commit\.[0-9a-f]{8}\.js)$/D', $candidate) === 1
            || self::isBoundedPublicFormat($candidate)
            || self::isStructuredName($candidate);
    }

    /**
     * Recognizes the complete public article URL used by a committed help link.
     *
     * @param string $completeUrl - Whole URL; empty values, credentials, queries and fragments grant no exception.
     * @return bool - Whether every route word fits the bounded public format.
     */
    public static function isHelpArticleUrl(string $completeUrl): bool
    {
        // A link assembled by the application must have this complete route before its literal fragments may stay quiet.
        if (preg_match('#^https://support\\.halaxy\\.com/hc/[a-z]{2}-[a-z]{2}/articles/[0-9]{12,13}-([A-Za-z]+(?:-[A-Za-z]+)*)$#D', $completeUrl, $matches) !== 1) {
            return false;
        }
        return self::hasPublicArticleLabel($matches[1]);
    }

    /**
     * Checks every title word before a complete article URL or route may skip scoring.
     *
     * @param string $label - Captured article title; empty or malformed words prevent an exception.
     * @return bool - Whether all words fit the title grammar, including its approved short joiners.
     */
    private static function hasPublicArticleLabel(string $label): bool
    {
        // A readable title cannot vouch for an unrelated opaque suffix.
        foreach (explode('-', $label) as $word) {
            // A random-looking label invalidates the whole URL, even after the known public hostname.
            if ($word !== 'a' && $word !== 'to' && $word !== 'in' && (strlen($word) < 3 || strlen($word) > 32
                || preg_match('/^(?:[A-Z]*[a-z]+|[A-Z]+|(?:[a-z]{3,}|[A-Z]{3,}|[A-Z][a-z]{2,})(?:[A-Z][a-z]{2,}|[A-Z]{3,})+)$/D', $word) !== 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keeps established help routes and clinical codes quiet only when every word fits their complete format.
     *
     * @param string $candidate - Whole candidate, never a field name or partial match.
     * @return bool - Whether the complete format and its words pass; false keeps the value eligible for a warning.
     */
    private static function isBoundedPublicFormat(string $candidate): bool
    {
        // A stored relative help link uses the article-only title grammar after its complete route matches.
        if (preg_match('#^/hc/[a-z]{2}-[a-z]{2}/articles/[0-9]{12,13}-([A-Za-z]+(?:-[A-Za-z]+)*)$#D', $candidate, $article) === 1) {
            return self::hasPublicArticleLabel($article[1]);
        }
        $formats = [
            '#^/hc/[a-z]{2}-[a-z]{2}/(?:sections|categories)/[0-9]{12}-([A-Za-z]+(?:-[A-Za-z]+)*)$#D',
            '/^(?:PH|PHVS)_([A-Za-z]+)_HL7_V[0-9]{1,4}$/D',
        ];
        // Either recognized format must account for the complete literal the user committed.
        foreach ($formats as $pattern) {
            // Capture the complete route label or clinical name, leaving no unexamined suffix.
            if (preg_match($pattern, $candidate, $matches) !== 1) {
                continue;
            }
            // Every route label or clinical name must be readable, even after a recognized public prefix.
            foreach (explode('-', $matches[1]) as $word) {
                // Only bounded words with the approved case structure belong to the public format.
                if (strlen($word) < 3 || strlen($word) > 32
                    || preg_match('/^(?:[A-Z]*[a-z]+|[A-Z]+|(?:[a-z]{3,}|[A-Z]{3,}|[A-Z][a-z]{2,})(?:[A-Z][a-z]{2,}|[A-Z]{3,})+)$/D', $word) !== 1) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Recognizes readable names and repository paths without letting their words hide an opaque tail.
     *
     * @param string $candidate - Whole literal content; empty or malformed names remain eligible for scoring.
     * @return bool - Whether every segment passes and at least two word segments supply a strict letter majority.
     */
    private static function isStructuredName(string $candidate): bool
    {
        // A committed path may start with two parent components or one rooted, hidden or current-directory prefix.
        foreach (['../../', '../', './', '/', '.'] as $prefix) {
            // Stopping at the first prefix keeps repeated leading dots or slashes outside the exception.
            if (str_starts_with($candidate, $prefix)) {
                $candidate = substr($candidate, strlen($prefix));
                break;
            }
        }
        // Require populated alphanumeric segments and reject every other punctuation character.
        if (preg_match('#^[A-Za-z0-9]+(?:[/._-]+[A-Za-z0-9]+)+$#D', $candidate) !== 1) {
            return false;
        }

        $alphanumericCount = $wordLetterCount = $wordSegmentCount = 0;
        // Readable directories do not excuse a random-looking filename; a failed split supplies no evidence to skip scoring.
        foreach (preg_split('#[/._-]+#', $candidate) ?: [] as $segment) {
            $segmentWordLetterCount = self::countSegmentWordLetters($segment);
            // A rejected segment prevents the whole value from receiving the public-name exception.
            if ($segmentWordLetterCount === null) {
                return false;
            }
            $alphanumericCount += strlen($segment);
            $wordLetterCount += $segmentWordLetterCount;
            $wordSegmentCount += (int) ($segmentWordLetterCount > 0);
        }

        return $wordSegmentCount >= 2 && $wordLetterCount * 2 > $alphanumericCount;
    }

    /**
     * Counts readable word letters without accepting an opaque suffix in the same segment.
     *
     * @param string $segment - Populated ASCII alphanumeric part supplied by the whole-name check.
     * @return int|null - Word-letter count; zero supplies no word evidence, and null rejects the whole name.
     */
    private static function countSegmentWordLetters(string $segment): ?int
    {
        // Long undivided segments can hold opaque values, so they remain eligible for a warning.
        if (strlen($segment) > 32) {
            return null;
        }
        // These finite code shapes contribute no word letters to the required majority.
        if (preg_match('/^(?:[vVxXrR][0-9]{1,4}|[0-9]{1,4}[bBeE]|[aA][0-9]{1,4}[bB]|FP[0-9]{1,4}|i18n|ec2|[mMtT][0-9]{2,3}|[0-9]{8}T[0-9]{4}(?:[0-9]{2})?Z)$/D', $segment) === 1) {
            return 0;
        }
        preg_match_all('/[A-Za-z]+|[0-9]+/', $segment, $matches);
        $runs            = $matches[0];
        $wordLetterCount = $digitRuns = 0;
        // Inspect every run so a readable opening cannot hide later random-looking text.
        foreach ($runs as $run) {
            // Numeric parts have tighter bounds when mixed with words, preserving warnings on opaque identifiers.
            if (ctype_digit($run)) {
                ++$digitRuns;
                // Repeated or long number runs prevent the name from receiving an exception.
                if (strlen($run) > (count($runs) === 1 ? 6 : 4) || $digitRuns > 2) {
                    return null;
                }
                continue;
            }
            // Every letter run needs ordinary casing or compound components of at least three letters.
            if (preg_match('/^(?:[A-Z]*[a-z]+|[A-Z]+|(?:[a-z]{3,}|[A-Z]{3,}|[A-Z][a-z]{2,})(?:[A-Z][a-z]{2,}|[A-Z]{3,})+)$/D', $run) !== 1
                || (count($runs) > 1 && strlen($run) < 3)) {
                return null;
            }
            $wordLetterCount += strlen($run) >= 3 ? strlen($run) : 0;
        }

        return $wordLetterCount;
    }
}
