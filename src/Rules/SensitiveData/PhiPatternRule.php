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
 * Detects contextual identifiers that look like protected health information.
 */
final readonly class PhiPatternRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for PHI pattern findings.
     */
    public const ID = 'sensitive-data.phi-pattern';

    /**
     * List the regex patterns enforced by this rule.
     *
     * @return list<array{name: string, pattern: string}> - one entry per identifier family; name keys the context check and message, pattern is the
     *                          matching regex
     */
    private function patterns(): array
    {
        return [
            ['name' => 'ssn', 'pattern' => '/\b\d{3}-\d{2}-\d{4}\b/'],
            ['name' => 'nhi', 'pattern' => '/\b[A-HJ-NP-Z]{3}\d{4}\b/'],
            ['name' => 'mrn', 'pattern' => '/\bMRN[-:\s]?[A-Z0-9]{6,10}\b/i'],
            ['name' => 'medicare', 'pattern' => '/\b[2-6]\d{3}\s?\d{5}\s?\d\b/'],
            ['name' => 'tfn', 'pattern' => '/\b\d{3}\s?\d{3}\s?\d{3}\b/'],
        ];
    }

    /**
     * Describe the PHI identifier pattern rule.
     *
     * @return RuleDefinition - the rule's id, pillar, tier, and default Warning/Medium severity used by the registry
     */
    public function definition(): RuleDefinition
    {
        // Warning, medium confidence: pattern hits need same-line context, so a false positive is plausible.
        return new RuleDefinition(
            id:              self::ID,
            name:            'PHI identifier pattern',
            pillar:          Pillar::SensitiveData,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find health identifier patterns when nearby text gives PHI context.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - one finding per non-comment, non-placeholder pattern hit that had PHI context on its line; empty
     *                                         when none qualify
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast bail: a PHI finding requires a context keyword on the same
        // line as the matched identifier. If the file has no PHI context
        // keyword anywhere, none of the per-pattern matches can ever pass
        // hasPhiContext().
        if (preg_match('/\b(?:health|medicare|mrn|nhi|patient|ssn|tax_file_number|tfn)\b/i', $analysisUnit->source) !== 1) {
            // No PHI context keyword anywhere, so no per-pattern match could survive hasPhiContext().
            return [];
        }

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);

        foreach ($this->patterns() as $definition) {
            preg_match_all($definition['pattern'], $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                [$candidateSecret, $offset] = $match;
                if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                    continue;
                }

                $lineNumber = SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset);
                $line       = $this->lineText($analysisUnit->source, $lineNumber);
                if (!$this->hasPhiContext($line, $definition['name'])) {
                    continue;
                }

                if ($this->isPlaceholderPhiLine($line, $candidateSecret, $analysisUnit->file->displayPath)) {
                    continue;
                }

                $preview    = SecretScannerHelper::redactedPreview($candidateSecret);
                $findings[] = SecretScannerHelper::finding(
                    analysisUnit: $analysisUnit,
                    ruleId:       self::ID,
                    message:      sprintf('Potential %s identifier detected: %s.', strtoupper($definition['name']), $preview),
                    line:         $lineNumber,
                    confidence:   Confidence::Medium,
                    detector:     $definition['name'],
                    preview:      $preview,
                    remediation:  'Use synthetic health identifiers in fixtures and keep real PHI out of source.',
                );
            }
        }

        return $findings;
    }

    /**
     * Check whether a matched identifier line contains health-data context.
     *
     * @param string $line - Source line that produced the candidate match.
     * @param string $detector - Pattern name (e.g. ssn, mrn) whose own keyword also counts as context.
     *
     * @return bool - true when the line names this detector or any health-domain keyword, so a raw pattern hit may become a finding
     */
    private function hasPhiContext(string $line, string $detector): bool
    {
        $normalized = strtolower($line);

        return str_contains($normalized, $detector)
               || str_contains($normalized, 'health')
               || str_contains($normalized, 'medicare')
               || str_contains($normalized, 'mrn')
               || str_contains($normalized, 'nhi')
               || str_contains($normalized, 'patient')
               || str_contains($normalized, 'ssn')
               || str_contains($normalized, 'tax_file_number')
               || str_contains($normalized, 'tfn');
    }

    /**
     * Suppress PHI-looking identifiers that are obvious examples or placeholders.
     *
     * @param string $line - Source line carrying the match, lower-cased here for keyword checks.
     * @param string $candidateSecret - Matched identifier text; punctuation is stripped before comparison.
     * @param string $displayPath - Unit path; example/placeholder wording only suppresses under docs/.
     *
     * @return bool - true to suppress the match as sample data: the known fund-membership placeholder, or an example/placeholder/sample line under
     *              docs/
     */
    private function isPlaceholderPhiLine(string $line, string $candidateSecret, string $displayPath): bool
    {
        $normalizedLine      = strtolower($line);
        $normalizedCandidate = preg_replace('/[^a-z0-9]/i', '', $candidateSecret) ?? '';

        if ($normalizedCandidate === '123456789' && str_contains($normalizedLine, 'patientfundmembershipnum')) {
            // The fund-membership sample 123456789 is a documented placeholder, never a real identifier.
            return true;
        }

        if (!str_starts_with($displayPath, 'docs/')) {
            // Outside docs/, treat every match as real; only documentation may carry sample identifiers.
            return false;
        }

        // Under docs/, suppress lines that flag themselves as examples, placeholders, or snippets.
        return str_contains($normalizedLine, 'example')
               || str_contains($normalizedLine, 'placeholder')
               || str_contains($normalizedLine, 'sample')
               || str_contains($normalizedLine, 'source_snippet');
    }

    /**
     * Return source text for a 1-based line number.
     *
     * @param string $source - Full unit source to slice by newline.
     * @param int    $lineNumber - 1-based line to read; out-of-range numbers yield an empty string.
     *
     * @return string - the requested line's text, or an empty string when the line number is past the end of the source
     */
    private function lineText(string $source, int $lineNumber): string
    {
        $lines = explode("\n", $source);

        return $lines[$lineNumber - 1] ?? '';
    }
}
