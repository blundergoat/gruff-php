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
 * Detects contextual identifiers that look like protected health information.
 */
final readonly class PhiPatternRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for PHI pattern findings.
     */
    public const ID = 'sensitive-data.phi-pattern';

    /**
     * @return list<array{name: string, pattern: string}>
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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<\GruffPhp\Finding\Finding> Findings for contextual PHI-like identifiers.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast bail: a PHI finding requires a context keyword on the same
        // line as the matched identifier. If the file has no PHI context
        // keyword anywhere, none of the per-pattern matches can ever pass
        // hasPhiContext().
        if (preg_match('/\b(?:health|medicare|mrn|nhi|patient|ssn|tax_file_number|tfn)\b/i', $analysisUnit->source) !== 1) {
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

                $line                  = $this->lineText($analysisUnit->source, SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset));
                if (!$this->hasPhiContext($line, $definition['name'])) {
                    continue;
                }

                $preview    = SecretScannerHelper::redactedPreview($candidateSecret);
                $findings[] = SecretScannerHelper::finding(
                    analysisUnit:        $analysisUnit,
                    ruleId:      self::ID,
                    message:     sprintf('Potential %s identifier detected: %s.', strtoupper($definition['name']), $preview),
                    line:        SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                    confidence:  Confidence::Medium,
                    detector:    $definition['name'],
                    preview:     $preview,
                    remediation: 'Use synthetic health identifiers in fixtures and keep real PHI out of source.',
                );
            }
        }

        return $findings;
    }

    /**
     * Check whether a matched identifier line contains health-data context.
     *
     * @return bool True when the line supports a PHI finding.
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
     * Return source text for a 1-based line number.
     *
     * @return string Line text, or an empty string when unavailable.
     */
    private function lineText(string $source, int $lineNumber): string
    {
        $lines = explode("\n", $source);

        return $lines[$lineNumber - 1] ?? '';
    }
}
