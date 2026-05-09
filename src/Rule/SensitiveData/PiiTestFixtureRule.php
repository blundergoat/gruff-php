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

final readonly class PiiTestFixtureRule implements SourceTextRuleInterface
{
    public const ID = 'sensitive-data.pii-test-fixture';

    /**
     * @return list<array{name: string, pattern: string}>
     */
    private function patterns(): array
    {
        return [
            ['name' => 'email', 'pattern' => '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i'],
            ['name' => 'phone', 'pattern' => '/\b(?:\+?1[-.\s]?)?\(?[2-9]\d{2}\)?[-.\s]\d{3}[-.\s]\d{4}\b/'],
            ['name' => 'address', 'pattern' => '/\b\d{1,5}\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,3}\s+(?:Avenue|Ave|Boulevard|Blvd|Drive|Dr|Lane|Ln|Road|Rd|Street|St|Terrace)\b/'],
        ];
    }

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'PII in test fixture',
            pillar: Pillar::SensitiveData,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        if (!SecretScannerHelper::isTestPath($unit->file->displayPath)) {
            return [];
        }

        $findings = [];
        foreach ($this->patterns() as $definition) {
            preg_match_all($definition['pattern'], $unit->source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                [$value, $offset] = $match;
                if ($this->isAllowedExample($value)) {
                    continue;
                }

                if ($definition['name'] === 'email' && $this->isAttributionEmail($unit->source, $offset)) {
                    continue;
                }

                $line = SecretScannerHelper::lineNumberForOffset($unit->source, $offset);
                $preview = SecretScannerHelper::redactedPreview($value);
                $findings[] = SecretScannerHelper::finding(
                    unit: $unit,
                    ruleId: self::ID,
                    message: sprintf('Realistic-looking %s found in a test fixture: %s.', $definition['name'], $preview),
                    line: $line,
                    confidence: Confidence::Medium,
                    detector: $definition['name'],
                    preview: $preview,
                    remediation: 'Use reserved example domains, obviously synthetic phone numbers, and fake fixture addresses.',
                );
            }
        }

        return $findings;
    }

    private function isAllowedExample(string $value): bool
    {
        $normalized = strtolower($value);

        return str_contains($normalized, '@example.')
            || str_contains($normalized, '@example-')
            || str_contains($normalized, '@test.')
            || str_contains($normalized, 'example')
            || str_contains($normalized, '555-010')
            || str_contains($normalized, '555 010');
    }

    private function isAttributionEmail(string $source, int $offset): bool
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($source, "\n", $offset);
        $lineEnd = $lineEnd === false ? strlen($source) : $lineEnd;
        $line = strtolower(substr($source, $lineStart, $lineEnd - $lineStart));

        return str_contains($line, '@author')
            || str_contains($line, 'copyright')
            || str_contains($line, '@copyright');
    }
}
