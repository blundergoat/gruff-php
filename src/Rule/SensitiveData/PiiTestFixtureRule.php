<?php

declare(strict_types=1);

namespace GruffPhp\Rule\SensitiveData;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\SourceTextRuleInterface;

/**
 * Detects test fixtures that appear to contain personally identifiable data.
 */
final readonly class PiiTestFixtureRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for PII fixture findings.
     */
    public const ID = 'sensitive-data.pii-test-fixture';

    /**
     * Reserved special-use domain suffixes (RFC 2606/6761/6762) whose addresses cannot receive real mail.
     * Each carries its label-boundary dot so `woo.local` matches while `mylocal.com` does not.
     */
    private const RESERVED_DOMAIN_SUFFIXES = ['.local', '.test', '.invalid', '.localhost', '.example'];

    /**
     * Marker words that identify an address fixture as deliberately synthetic.
     */
    private const SYNTHETIC_ADDRESS_MARKERS = ['anytown', 'demo', 'fake', 'sample', 'test'];

    /**
     * List the regex patterns enforced by this rule.
     *
     * @return list<array{name: string, pattern: string}> - one entry per PII family, each a detector name paired with its match regex; order is the
     *                          scan order
     */
    private function patterns(): array
    {
        // One detector name plus its regex per PII family; the name only gates the email-only
        // attribution check, the finding message, and the detector field, not the value allow-list.
        return [
            ['name' => 'email', 'pattern' => '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i'],
            ['name' => 'phone', 'pattern' => '/\b(?:\+?1[-.\s]?)?\(?[2-9]\d{2}\)?[-.\s]\d{3}[-.\s]\d{4}\b/'],
            ['name' => 'address', 'pattern' => '/\b\d{1,5}\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,3}\s+(?:Avenue|Ave|Boulevard|Blvd|Drive|Dr|Lane|Ln|Road|Rd|Street|St|Terrace)\b/'],
        ];
    }

    /**
     * Describe the PII test fixture rule.
     *
     * @return RuleDefinition - id, name, pillar, tier, and the warning/medium defaults a caller applies unless overridden
     */
    public function definition(): RuleDefinition
    {
        // Warning, medium confidence: realistic-looking fixtures are likely synthetic but worth a reviewer's eye.
        return new RuleDefinition(
            id:                  self::ID,
            name:                'PII in test fixture',
            pillar:              Pillar::SensitiveData,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Warning,
            confidence:          Confidence::Medium,
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
     * Find realistic PII-like values inside test fixture files.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> - one finding per realistic PII match left after allow-list and attribution filtering; empty for
     *                                         non-test paths or clean fixtures
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!SecretScannerHelper::isTestPath($analysisUnit->file->displayPath)) {
            // This rule only governs fixtures, so production paths produce nothing.
            return [];
        }

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        foreach ($this->patterns() as $definition) {
            preg_match_all($definition['pattern'], $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                [$candidateFixture, $offset] = $match;
                if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                    continue;
                }

                if ($this->isSuppressedMatch($definition['name'], $candidateFixture, $analysisUnit->source, $offset)) {
                    continue;
                }

                $line       = SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset);
                $preview    = SecretScannerHelper::redactedPreview($candidateFixture);
                $findings[] = SecretScannerHelper::finding(
                    analysisUnit: $analysisUnit,
                    ruleId:       self::ID,
                    message:      sprintf('Realistic-looking %s found in a test fixture: %s.', $definition['name'], $preview),
                    line:         $line,
                    confidence:   Confidence::Medium,
                    detector:     $definition['name'],
                    preview:      $preview,
                    remediation:  'Use reserved example domains (.example, .test, .invalid, .local, .localhost), '
                        . 'phone numbers in the 555-010x block, and addresses with a synthetic marker word such as Test or Anytown.',
                );
            }
        }

        return $findings;
    }

    /**
     * Decide whether a PII match falls under one of the rule's built-in allowances.
     *
     * @param string $detectorName - Detector family of the match (email, phone, or address); gates the
     *                             detector-specific allowances.
     * @param string $candidateFixture - Matched fixture text under test.
     * @param string $source - Full unit source, used for line-context scans.
     * @param int    $offset - Byte offset of the match within the source.
     *
     * @return bool - true when the match is a known-synthetic example, attribution metadata, a reserved-domain email, or a marker-word
     *              address, so the caller skips it
     */
    private function isSuppressedMatch(string $detectorName, string $candidateFixture, string $source, int $offset): bool
    {
        if ($this->isAllowedExample($candidateFixture)) {
            return true;
        }

        if ($detectorName === 'email') {
            // Maintainer attribution lines and undeliverable reserved-zone domains are not fixture PII.
            return $this->isAttributionEmail($source, $offset) || $this->isReservedDomainEmail($candidateFixture);
        }

        // Addresses carrying a synthetic marker word already follow the remediation; phones rely on isAllowedExample alone.
        return $detectorName === 'address' && $this->isSyntheticAddress($source, $offset, $candidateFixture);
    }

    /**
     * Allow clearly synthetic example values.
     *
     * @param string $candidateFixture - Matched fixture text, lower-cased here before substring checks.
     *
     * @return bool - true to suppress the match as a known-synthetic example (reserved domain or 555-010x phone block); false lets it be flagged
     */
    private function isAllowedExample(string $candidateFixture): bool
    {
        $normalized = strtolower($candidateFixture);

        return str_contains($normalized, '@example.')
               || str_contains($normalized, '@example-')
               || str_contains($normalized, '@test.')
               || str_contains($normalized, 'example')
               || str_contains($normalized, '555-010')
               || str_contains($normalized, '555 010');
    }

    /**
     * Ignore email addresses that appear in attribution or copyright lines.
     *
     * @param string $source - Full unit source, used to recover the physical line around the match.
     * @param int    $offset - Byte offset of the email match within the source.
     *
     * @return bool - true when the email sits on an author/copyright line (maintainer metadata, not fixture PII) and should be skipped
     */
    private function isAttributionEmail(string $source, int $offset): bool
    {
        $line = $this->lineAroundOffset($source, $offset);

        return str_contains($line, '@author')
               || str_contains($line, 'copyright')
               || str_contains($line, '@copyright');
    }

    /**
     * Allow email addresses whose domain ends in a reserved special-use suffix.
     *
     * @param string $candidateFixture - Matched email text; only the domain part after the last `@` is examined.
     *
     * @return bool - true when the domain ends in a reserved suffix (.local, .test, .invalid, .localhost, .example), so the address is
     *              undeliverable by construction and should be skipped
     */
    private function isReservedDomainEmail(string $candidateFixture): bool
    {
        $atPosition = strrpos($candidateFixture, '@');
        if ($atPosition === false) {
            // Without an @ there is no domain to classify, so the match stays eligible for flagging.
            return false;
        }

        $domain = strtolower(substr($candidateFixture, $atPosition + 1));

        foreach (self::RESERVED_DOMAIN_SUFFIXES as $reservedSuffix) {
            if (str_ends_with($domain, $reservedSuffix)) {
                // The suffix's leading dot enforces a label boundary, so only true reserved-zone domains pass.
                return true;
            }
        }

        // A routable-looking domain keeps the match eligible: realistic emails in fixtures still flag.
        return false;
    }

    /**
     * Allow addresses whose matched tokens or surrounding line carry a synthetic marker word.
     *
     * @param string $source - Full unit source, used to recover the physical line around the match.
     * @param int    $offset - Byte offset of the address match within the source.
     * @param string $candidateFixture - Matched address text, scanned alongside the line.
     *
     * @return bool - true when a synthetic marker (test, fake, sample, demo, anytown) appears as a whole word in the matched tokens or their
     *              line, mirroring the line-context scan the email attribution check uses
     */
    private function isSyntheticAddress(string $source, int $offset, string $candidateFixture): bool
    {
        $line = $this->lineAroundOffset($source, $offset);

        // Match a synthetic marker as a whole word ("Test St", "Anytown"), not as a substring of a real
        // word such as "latest", so realistic addresses are never suppressed by incidental letters.
        return preg_match(
            '/\b(?:' . implode('|', self::SYNTHETIC_ADDRESS_MARKERS) . ')\b/i',
            $candidateFixture . ' ' . $line,
        ) === 1;
    }

    /**
     * Recover the lower-cased physical line that contains a byte offset.
     *
     * @param string $source - Full unit source being scanned.
     * @param int    $offset - Byte offset inside the source.
     *
     * @return string - the lower-cased text of the line holding the offset, for context-marker scans
     */
    private function lineAroundOffset(string $source, int $offset): string
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd   = strpos($source, "\n", $offset);
        $lineEnd   = $lineEnd === false ? strlen($source) : $lineEnd;

        return strtolower(substr($source, $lineStart, $lineEnd - $lineStart));
    }
}
