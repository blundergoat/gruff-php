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
 * Reports a realistic email, phone number or street address in a test fixture, so the user can swap in example data that
 * can never point at a real person.
 *
 * A user who pasted `'jane.doe@gmail.com'` into a test gets one warning on that line; files outside test paths are never read.
 * Values that are plainly synthetic stay quiet:
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
     * Reserved special-use domain suffixes (RFC 2606/6761/6762) whose addresses cannot receive real mail.
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
        // The name decides which allowances apply and appears in the finding message; it never widens what counts as synthetic.
        return [
            ['name' => 'email', 'pattern' => '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i'],
            ['name' => 'phone', 'pattern' => '/\b(?:\+?1[-.\s]?)?\(?[2-9]\d{2}\)?[-.\s]\d{3}[-.\s]\d{4}\b/'],
            ['name' => 'address', 'pattern' => '/\b\d{1,5}\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,3}\s+(?:Avenue|Ave|Boulevard|Blvd|Drive|Dr|Lane|Ln|Road|Rd|Street|St|Terrace)\b/'],
        ];
    }

    /**
     * Describes the rule for `list-rules` and for each finding it reports.
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
            // Off unless a project sets `enabled: true`: precision-floor M02 found it right on 35 of 47 judged findings after its repair, below its 0.80 floor,
            // so it is off by default rather than deleted (ADR-034).
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
     * Reports each realistic PII value in a test file that none of the synthetic-data allowances covers.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - One finding per realistic value; empty for a file outside test paths or one using
     *                                         only example data
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Only test fixtures are in scope; real contact data in application code is a different review.
        if (!SecretScannerHelper::isTestPath($analysisUnit->file->displayPath)) {
            return [];
        }

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        // Each PII shape is searched across the whole file text, strings and data files alike.
        foreach ($this->piiPatterns() as $piiPattern) {
            // Collect every match of this shape with its byte offset, so a finding can name its line.
            preg_match_all($piiPattern['pattern'], $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

            // Each match is judged on its own, so one example address does not hide a real one on the next line.
            foreach ($matches[0] as $match) {
                [$matchedValue, $matchOffset] = $match;
                // A value in a comment documents the fixture rather than feeding the test.
                if (SecretScannerHelper::isInsideComment($matchOffset, $commentRanges)) {
                    continue;
                }

                // An example domain, a URL credential or another synthetic shape is not a real person's data.
                if ($this->isKnownNotPersonalData($piiPattern['name'], $matchedValue, $analysisUnit->source, $matchOffset)) {
                    continue;
                }

                $line          = SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $matchOffset);
                $displayMarker = SecretScannerHelper::categoryMarker($piiPattern['name']);
                $findings[]    = SecretScannerHelper::finding(
                    analysisUnit: $analysisUnit,
                    ruleId:       self::ID,
                    message:      sprintf('Realistic-looking %s found in a test fixture: %s.', $piiPattern['name'], $displayMarker),
                    line:         $line,
                    confidence:   Confidence::Medium,
                    detector:     $piiPattern['name'],
                    displayMarker: $displayMarker,
                    remediation:  'Use reserved example domains (.example, .test, .invalid, .local, .localhost), '
                        . 'phone numbers in the 555-010x block, and addresses with a synthetic marker word such as Test or Anytown.',
                );
            }
        }

        return $findings;
    }

    /**
     * Reports whether a match is example data or not a person's contact detail at all, so the user sees no finding for it.
     *
     * @param string $piiKind - Which pattern matched: email, phone or address; each kind has its own allowances.
     * @param string $matchedValue - The matched text.
     * @param string $source - Full file text, for checks that read the rest of the line.
     * @param int    $matchOffset - Byte offset of the match in the file.
     *
     * @return bool - true for an example value, an author line, an undeliverable domain, URL userinfo, an auth realm, a mirrored
     *              placeholder email, or a marked address; false keeps the finding
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
     * Reports whether an email sits on an `@author` or copyright line, which credits a maintainer rather than feeding a test.
     *
     * @param string $source - Full file text, used to read the whole line around the match.
     * @param int    $matchOffset - Byte offset of the email in the file.
     *
     * @return bool - true on an author or copyright line; false for any other line
     */
    private function sitsOnAuthorLine(string $source, int $matchOffset): bool
    {
        $lowercaseLine = $this->lowercaseLineAt($source, $matchOffset);

        return str_contains($lowercaseLine, '@author')
               || str_contains($lowercaseLine, 'copyright')
               || str_contains($lowercaseLine, '@copyright');
    }

    /**
     * Reports whether an email's domain ends in a reserved suffix such as `.test`, so mail to it can never be delivered.
     *
     * @param string $matchedValue - The matched email; only the part after the last `@` is read.
     *
     * @return bool - true for .local, .test, .invalid, .localhost or .example; false for a routable-looking domain
     */
    private function usesUndeliverableDomain(string $matchedValue): bool
    {
        $atPosition = strrpos($matchedValue, '@');
        // Without an `@` there is no domain to judge, so the match keeps its finding.
        if ($atPosition === false) {
            return false;
        }

        $domain = strtolower(substr($matchedValue, $atPosition + 1));

        // Each reserved suffix keeps its leading dot, so only a whole label such as `woo.local` matches.
        foreach (self::RESERVED_DOMAIN_SUFFIXES as $reservedSuffix) {
            // A reserved zone means the address is made up by construction.
            if (str_ends_with($domain, $reservedSuffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether an email-shaped match is URL userinfo or an HTTP auth realm, such as `Digest realm="testrealm@host.com"`.
     *
     * @param string $source - Full file text, used to read the line before the match.
     * @param int    $matchOffset - Byte offset of the match in the file.
     *
     * @return bool - true when the line just before the match ends `scheme://`, `scheme://user:` or `realm="`; false otherwise
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
     * Reports whether an email's local part repeats its domain's name, the shape of a placeholder such as `bar@bar.com`.
     *
     * @param string $matchedValue - The matched email.
     *
     * @return bool - true when the local part equals the domain label before the top-level domain; false for any other mailbox
     */
    private function repeatsDomainAsMailbox(string $matchedValue): bool
    {
        $atPosition = strrpos($matchedValue, '@');
        // Without an `@` there is no local part to compare.
        if ($atPosition === false) {
            return false;
        }

        $domainLabels = explode('.', strtolower(substr($matchedValue, $atPosition + 1)));

        // A real mailbox rarely names itself after its own domain; a test placeholder often does.
        return count($domainLabels) >= 2 && strtolower(substr($matchedValue, 0, $atPosition)) === $domainLabels[count($domainLabels) - 2];
    }

    /**
     * Reports whether an address, or the line around it, carries a marker word such as "Test" or "Anytown".
     *
     * @param string $source - Full file text, used to read the whole line around the match.
     * @param int    $matchOffset - Byte offset of the address in the file.
     * @param string $matchedValue - The matched address, checked together with its line.
     *
     * @return bool - true when a marker appears as a whole word in the address or its line; false for a realistic address
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
     * @param string $source - Full file text.
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
