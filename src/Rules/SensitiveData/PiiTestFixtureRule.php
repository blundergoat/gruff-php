<?php

declare(strict_types=1);

namespace GruffPhp\Rules\SensitiveData;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\SourceTextRuleInterface;

/**
 * Flags realistic-looking contact details in test fixtures so callers can recommend recognisable example data.
 *
 * Files outside test paths are not scanned by this rule.
 * The following matches are exempt:
 * - reserved example domains, the 555-010x phone block, and addresses with a marker word such as "Test" or "Anytown";
 * - author and copyright lines, URL userinfo such as `http://user:pass@host`, and an HTTP auth `realm="..."` value;
 * - a local part that repeats its domain's name, such as `foo@foo.com`.
 */
final readonly class PiiTestFixtureRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for PII fixture findings.
     */
    public const ID = 'sensitive-data.pii-test-fixture';

    /**
     * Reserved domain suffixes that this rule treats as synthetic fixture data.
     *
     * Each keeps its leading dot, so `woo.local` matches while `mylocal.com` does not.
     */
    private const RESERVED_DOMAIN_SUFFIXES = ['.local', '.test', '.invalid', '.localhost', '.example'];

    /**
     * Words that mark an address as deliberately made up, such as `123 Test St`.
     */
    private const SYNTHETIC_ADDRESS_MARKERS = ['anytown', 'demo', 'fake', 'sample', 'test'];

    /**
     * Line text that, directly before an email-shaped match, makes it URL userinfo or an HTTP auth realm rather than a mailbox.
     *
     * The first branch ends at `scheme://` or `scheme://user:`; `mailto:` has no `//`, so a mailto address still reports.
     * The second ends at `realm=`, `realm="` or the escaped `realm=\"` found inside double-quoted PHP strings.
     */
    private const URL_USER_OR_REALM_PREFIX = <<<'REGEX'
        ~(?:[a-z][a-z0-9+.\-]*://(?:[^\s/?#@:'"]*:)?|\brealm=\\?["']?)$~i
        REGEX;

    /**
     * Lists the PII shapes the rule looks for, each with the name shown in its findings.
     *
     * @return list<array{name: string, pattern: string}> - email, phone and address patterns, in the order they are scanned
     */
    private function piiPatterns(): array
    {
        // The name selects kind-specific allowances and labels findings; the regex defines the candidate shape.
        return [
            ['name' => 'email', 'pattern' => '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i'],
            ['name' => 'phone', 'pattern' => '/\b(?:\+?1[-.\s]?)?\(?[2-9]\d{2}\)?[-.\s]\d{3}[-.\s]\d{4}\b/'],
            ['name' => 'address', 'pattern' => '/\b\d{1,5}\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,3}\s+(?:Avenue|Ave|Boulevard|Blvd|Drive|Dr|Lane|Ln|Road|Rd|Street|St|Terrace)\b/'],
        ];
    }

    /**
     * Describes the rule for list-rules output and for each finding it reports.
     *
     * @return RuleDefinition - id, name, pillar, tier, and the warning/medium defaults a caller applies unless overridden
     */
    public function definition(): RuleDefinition
    {
        // Warning, medium confidence: a realistic-looking fixture value is often synthetic, but worth a reviewer's look.
        return new RuleDefinition(
            id:                  self::ID,
            name:                'PII in test fixture',
            pillar:              Pillar::SensitiveData,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Warning,
            confidence:          Confidence::Medium,
            // Disabled by default under ADR-034 because calibration did not meet this rule's precision floor.
            isEnabledByDefault: false,
            falsePositiveShapes: [
                [
                    'shape'      => 'Emails on reserved special-use domains (get_customer_test@woo.local, admin@app.test).',
                    'mitigation' => 'Exempt automatically: a domain ending in .local, .test, .invalid, .localhost, or .example cannot receive real mail.',
                ],
                [
                    'shape'      => 'Addresses whose matched tokens or surrounding line carry a synthetic marker (123 Test St; 134 Main St, Anytown, USA).',
                    'mitigation' => 'Exempt automatically when test, fake, sample, demo, or anytown appears as a whole word in the matched address or its line.',
                ],
            ],
        );
    }

    /**
     * Reports unexempted email, phone and address matches in test files as realistic-looking contact data.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext  - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Unexempted matches outside comments; empty when no match qualifies.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Only test fixtures are in scope; real contact data in application code is a different review.
        if (!SecretScannerHelper::isTestPath($analysisUnit->file->displayPath)) {
            return [];
        }

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        // Run email, phone, and address patterns in turn so each match carries its specific category label and advice.
        foreach ($this->piiPatterns() as $piiPattern) {
            // Collect every match of this shape with its byte offset, so a finding can name its line and column.
            preg_match_all($piiPattern['pattern'], $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

            // Each match is judged on its own, so one example address does not hide a real one on the next line.
            foreach ($matches[0] as $match) {
                [$matchedValue, $matchOffset] = $match;
                // Skip documentation examples inside PHP comments so docblocks stay quiet.
                if (SecretScannerHelper::isInsideComment($matchOffset, $commentRanges)) {
                    continue;
                }

                // Skip reserved domains, 555-010x numbers, author credits, URL userinfo, and marked synthetic addresses.
                if ($this->isKnownNotPersonalData($piiPattern['name'], $matchedValue, $analysisUnit->source, $matchOffset)) {
                    continue;
                }

                $displayMarker = SecretScannerHelper::categoryMarker($piiPattern['name']);
                $findings[]    = SecretScannerHelper::findingAtOffset(
                    analysisUnit:  $analysisUnit,
                    ruleId:        self::ID,
                    message:       sprintf('Realistic-looking %s found in a test fixture: %s.', $piiPattern['name'], $displayMarker),
                    offset:        $matchOffset,
                    confidence:    Confidence::Medium,
                    detector:      $piiPattern['name'],
                    displayMarker: $displayMarker,
                    remediation:   'Use reserved example domains (.example, .test, .invalid, .local, .localhost), '
                        . 'phone numbers in the 555-010x block, and addresses with a synthetic marker word such as Test or Anytown.',
                );
            }
        }

        return $findings;
    }

    /**
     * Decides whether a value, its kind, or its surrounding line exempts a match from fixture findings.
     *
     * @param string $piiKind      - Which pattern matched: email, phone or address; each kind has its own allowances.
     * @param string $matchedValue - The matched text.
     * @param string $source       - Full file text, for checks that read the rest of the line.
     * @param int    $matchOffset  - Byte offset of the match in the file.
     *
     * @return bool - True when an allowance excludes the match; false leaves it eligible for a finding.
     */
    private function isKnownNotPersonalData(string $piiKind, string $matchedValue, string $source, int $matchOffset): bool
    {
        // A reserved example value is fine in any fixture, whatever its kind.
        if ($this->isReservedExampleValue($matchedValue)) {
            return true;
        }

        // Email-shaped text has the most look-alikes: author credits, test domains, URL credentials, auth realms and placeholders.
        if ($piiKind === 'email') {
            return $this->sitsOnAuthorLine($source, $matchOffset) || $this->usesUndeliverableDomain($matchedValue)
                || $this->isUrlUserOrAuthRealm($source, $matchOffset) || $this->repeatsDomainAsMailbox($matchedValue);
        }

        // An address with a marker word already follows the advice; a phone number relies on the example check alone.
        return $piiKind === 'address' && $this->carriesSyntheticAddressMarker($source, $matchOffset, $matchedValue);
    }

    /**
     * Reports whether a value is reserved example data, such as an `@example.com` email or a 555-010x phone number.
     *
     * @param string $matchedValue - The matched text, compared case-insensitively.
     *
     * @return bool - true for an example domain or the reserved phone block; false lets the other checks decide
     */
    private function isReservedExampleValue(string $matchedValue): bool
    {
        $lowercaseValue = strtolower($matchedValue);

        return str_contains($lowercaseValue, '@example.')
               || str_contains($lowercaseValue, '@example-')
               || str_contains($lowercaseValue, '@test.')
               || str_contains($lowercaseValue, 'example')
               || str_contains($lowercaseValue, '555-010')
               || str_contains($lowercaseValue, '555 010');
    }

    /**
     * Reports whether the email line contains an author or copyright marker associated with maintainer credits.
     *
     * @param string $source      - Full file text, used to read the whole line around the match.
     * @param int    $matchOffset - Byte offset of the email in the file.
     *
     * @return bool - True when the line contains an author or copyright marker; false otherwise.
     */
    private function sitsOnAuthorLine(string $source, int $matchOffset): bool
    {
        $lowercaseLine = $this->lowercaseLineAt($source, $matchOffset);

        return str_contains($lowercaseLine, '@author')
               || str_contains($lowercaseLine, 'copyright')
               || str_contains($lowercaseLine, '@copyright');
    }

    /**
     * Recognises email suffixes that the rule exempts as reserved test domains.
     *
     * @param string $matchedValue - The matched email; only the part after the last @ is read.
     *
     * @return bool - True for a listed reserved suffix; false otherwise.
     */
    private function usesUndeliverableDomain(string $matchedValue): bool
    {
        $atPosition = strrpos($matchedValue, '@');
        // Without an at sign there is no domain suffix to test.
        if ($atPosition === false) {
            return false;
        }

        $domain = strtolower(substr($matchedValue, $atPosition + 1));

        // Each reserved suffix keeps its leading dot, so only a whole label such as woo.local matches.
        foreach (self::RESERVED_DOMAIN_SUFFIXES as $reservedSuffix) {
            // Matching any reserved test suffix confirms the mailbox cannot route real mail.
            if (str_ends_with($domain, $reservedSuffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether text before an email-shaped match has a supported URL-userinfo or HTTP-auth-realm prefix.
     *
     * @param string $source      - Full file text, used to read the line before the match.
     * @param int    $matchOffset - Byte offset of the match in the file.
     *
     * @return bool - True for a supported URL-userinfo or auth-realm prefix immediately before the match; false otherwise.
     */
    private function isUrlUserOrAuthRealm(string $source, int $matchOffset): bool
    {
        $lineStart        = strrpos(substr($source, 0, $matchOffset), "\n");
        $lineStartOffset  = $lineStart === false ? 0 : $lineStart + 1;
        $textBeforeMatch  = substr($source, $lineStartOffset, $matchOffset - $lineStartOffset);

        // The text right before the match must end a URL scheme (with an optional user) or open a realm value.
        return preg_match(self::URL_USER_OR_REALM_PREFIX, $textBeforeMatch) === 1;
    }

    /**
     * Reports whether an email local part case-insensitively equals the penultimate domain label, a placeholder shape such as bar@bar.com.
     *
     * @param string $matchedValue - The matched email.
     *
     * @return bool - true when the local part case-insensitively equals the domain label before the top-level domain; false for any other mailbox
     */
    private function repeatsDomainAsMailbox(string $matchedValue): bool
    {
        $atPosition = strrpos($matchedValue, '@');
        // Without an at sign there is no local part to compare.
        if ($atPosition === false) {
            return false;
        }

        $domainLabels = explode('.', strtolower(substr($matchedValue, $atPosition + 1)));

        // Equality is treated as placeholder evidence; it does not establish whether the mailbox is deliverable.
        return count($domainLabels) >= 2 && strtolower(substr($matchedValue, 0, $atPosition)) === $domainLabels[count($domainLabels) - 2];
    }

    /**
     * Reports whether an address, or the line around it, carries a marker word such as "Test" or "Anytown".
     *
     * @param string $source       - Full file text, used to read the whole line around the match.
     * @param int    $matchOffset  - Byte offset of the address in the file.
     * @param string $matchedValue - The matched address, checked together with its line.
     *
     * @return bool - True for a whole-word marker in the address or its line; false when neither contains one.
     */
    private function carriesSyntheticAddressMarker(string $source, int $matchOffset, string $matchedValue): bool
    {
        $lowercaseLine = $this->lowercaseLineAt($source, $matchOffset);

        // A marker must be a whole word ("Test St"), so an incidental "latest" never hides a realistic address.
        return preg_match(
            '/\b(?:' . implode('|', self::SYNTHETIC_ADDRESS_MARKERS) . ')\b/i',
            $matchedValue . ' ' . $lowercaseLine,
        ) === 1;
    }

    /**
     * Returns the lower-cased text of the line that holds a byte offset.
     *
     * @param string $source      - Full file text.
     * @param int    $matchOffset - Byte offset inside the file.
     *
     * @return string - the whole line, lower-cased for marker checks; empty for a blank line
     */
    private function lowercaseLineAt(string $source, int $matchOffset): string
    {
        $lineStart = strrpos(substr($source, 0, $matchOffset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd   = strpos($source, "\n", $matchOffset);
        $lineEnd   = $lineEnd === false ? strlen($source) : $lineEnd;

        return strtolower(substr($source, $lineStart, $lineEnd - $lineStart));
    }
}
