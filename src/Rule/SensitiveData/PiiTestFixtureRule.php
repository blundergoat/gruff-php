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
            id:              self::ID,
            name:            'PII in test fixture',
            pillar:          Pillar::SensitiveData,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find realistic PII-like values inside test fixture files.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
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

                if ($this->isAllowedExample($candidateFixture)) {
                    continue;
                }

                if ($definition['name'] === 'email' && $this->isAttributionEmail($analysisUnit->source, $offset)) {
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
                    remediation:  'Use reserved example domains, obviously synthetic phone numbers, and fake fixture addresses.',
                );
            }
        }

        // One finding per fixture match that is neither a synthetic example nor an attribution email.
        return $findings;
    }

    /**
     * Allow clearly synthetic example values.
     *
     * @param string $candidateFixture Matched fixture text, lower-cased here before substring checks.
     *
     * @return bool - true to suppress the match as a known-synthetic example (reserved domain or 555-010x phone block); false lets it be flagged
     */
    private function isAllowedExample(string $candidateFixture): bool
    {
        $normalized = strtolower($candidateFixture);

        // True for reserved example/test domains and the 555-010x phone block, all guaranteed non-real.
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
     * @param string $source Full unit source, used to recover the physical line around the match.
     * @param int    $offset Byte offset of the email match within the source.
     *
     * @return bool - true when the email sits on an author/copyright line (maintainer metadata, not fixture PII) and should be skipped
     */
    private function isAttributionEmail(string $source, int $offset): bool
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd   = strpos($source, "\n", $offset);
        $lineEnd   = $lineEnd === false ? strlen($source) : $lineEnd;
        $line      = strtolower(substr($source, $lineStart, $lineEnd - $lineStart));

        // True for author/copyright lines, where a maintainer email is metadata rather than fixture PII.
        return str_contains($line, '@author')
               || str_contains($line, 'copyright')
               || str_contains($line, '@copyright');
    }
}
