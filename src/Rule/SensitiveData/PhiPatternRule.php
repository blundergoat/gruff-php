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

final readonly class PhiPatternRule implements SourceTextRuleInterface
{
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

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'PHI identifier pattern',
            pillar: Pillar::SensitiveData,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $findings = [];

        foreach ($this->patterns() as $definition) {
            preg_match_all($definition['pattern'], $unit->source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                [$value, $offset] = $match;
                $line = $this->lineText($unit->source, SecretScannerHelper::lineNumberForOffset($unit->source, $offset));
                if (!$this->hasPhiContext($line, $definition['name'])) {
                    continue;
                }

                $preview = SecretScannerHelper::redactedPreview($value);
                $findings[] = SecretScannerHelper::finding(
                    unit: $unit,
                    ruleId: self::ID,
                    message: sprintf('Potential %s identifier detected: %s.', strtoupper($definition['name']), $preview),
                    line: SecretScannerHelper::lineNumberForOffset($unit->source, $offset),
                    confidence: Confidence::Medium,
                    detector: $definition['name'],
                    preview: $preview,
                    remediation: 'Use synthetic health identifiers in fixtures and keep real PHI out of source.',
                );
            }
        }

        return $findings;
    }

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

    private function lineText(string $source, int $lineNumber): string
    {
        $lines = explode("\n", $source);

        return $lines[$lineNumber - 1] ?? '';
    }
}
