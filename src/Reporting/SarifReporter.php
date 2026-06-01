<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\Severity;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleRegistry;
use JsonException;

/**
 * Renders analysis reports in SARIF format.
 */
final readonly class SarifReporter
{
    /**
     * Render findings as a SARIF 2.1.0 JSON document.
     *
     * @param AnalysisReport $report Analysis report to render.
     * @return string SARIF JSON document or encoded error payload.
     */
    public function render(AnalysisReport $report): string
    {
        $rules = [];
        foreach (RuleRegistry::defaults()->all() as $rule) {
            $definition             = $rule->definition();
            $rules[$definition->id] = $this->rule($definition);
        }

        foreach ($report->findings as $finding) {
            $rules[$finding->ruleId] ??= [
                'id' => $finding->ruleId,
                'name' => $finding->ruleId,
                'shortDescription' => [
                    'text' => $finding->ruleId,
                ],
                'properties' => [
                    'pillar' => $finding->pillar->value,
                    'severity' => $finding->severity->value,
                    'confidence' => $finding->confidence->value,
                    'tier' => $finding->tier->value,
                ],
            ];
        }

        ksort($rules, SORT_STRING);
        $ruleIds     = array_keys($rules);
        $ruleIndexes = array_flip($ruleIds);
        $properties  = [
            'gruffSchemaVersion' => AnalysisReport::SCHEMA_VERSION,
        ];
        if ($report->score !== null) {
            $properties['score'] = $report->score->composite->score;
            $properties['grade'] = $report->score->composite->letter;
        }

        $payload = [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [[
                'tool' => [
                    'driver' => [
                        'name' => AnalysisReport::TOOL_NAME,
                        'semanticVersion' => $report->toolVersion,
                        'rules' => array_values($rules),
                    ],
                ],
                'results' => array_map(fn (Finding $finding): array => $this->result($finding, (int) $ruleIndexes[$finding->ruleId]), $report->findings),
                'properties' => $properties,
            ]],
        ];

        try {
            // Trailing newline keeps the document POSIX-line-terminated when redirected to a .sarif file.
            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (JsonException $exception) {
            // Encoding failure still emits parseable JSON so a SARIF consumer reports an error instead of choking.
            return sprintf('{"error":"Unable to encode SARIF: %s"}%s', addslashes($exception->getMessage()), PHP_EOL);
        }
    }

    /**
     * Render one registry definition as a SARIF driver rule.
     *
     * @param RuleDefinition $definition Native rule definition.
     * @return array{
     *     id: string,
     *     name: string,
     *     shortDescription: array{text: string},
     *     fullDescription: array{text: string},
     *     help: array{text: string},
     *     properties: array<string, bool|float|int|string|array<array-key, bool|float|int|string|array<array-key, bool|float|int|string>>>
     * }
     */
    private function rule(RuleDefinition $definition): array
    {
        $properties = [
            'pillar' => $definition->pillar->value,
            'tier' => $definition->tier->value,
            'defaultSeverity' => $definition->defaultSeverity->value,
            'confidence' => $definition->confidence->value,
            'defaultEnabled' => $definition->isEnabledByDefault,
        ];
        if ($definition->secondaryPillars !== []) {
            $properties['secondaryPillars'] = array_map(
                static fn (Pillar $pillar): string => $pillar->value,
                $definition->secondaryPillars,
            );
        }
        $single = $definition->severityThreshold;
        if ($single instanceof \GruffPhp\Config\SeverityThreshold) {
            $properties['threshold'] = $single->threshold;
            $properties['severity']  = $single->severity->value;
        } elseif ($definition->defaultThresholds !== []) {
            $properties['thresholds'] = $definition->defaultThresholds;
        }
        if ($definition->defaultOptions !== []) {
            $properties['options'] = $definition->defaultOptions;
        }

        // Help and full description reuse the one-line rule description; gruff has no separate long-form help text.
        return [
            'id' => $definition->id,
            'name' => $definition->name,
            'shortDescription' => [
                'text' => $definition->name,
            ],
            'fullDescription' => [
                'text' => $definition->description(),
            ],
            'help' => [
                'text' => $definition->description(),
            ],
            'properties' => $properties,
        ];
    }

    /**
     * Build one SARIF result payload for a finding.
     *
     * @param Finding $finding   Finding to serialize into a single SARIF result entry.
     * @param int     $ruleIndex Zero-based offset of this finding's rule in the driver `rules` array, so the
     *                           result can reference its rule by index rather than repeating the descriptor.
     * @return array<string, mixed>
     */
    private function result(Finding $finding, int $ruleIndex): array
    {
        $uri = str_replace('\\', '/', $finding->filePath);
        $uri = (string) preg_replace('/^(?:\\.\\/)+/', '', $uri);

        $physicalLocation = [
            'artifactLocation' => [
                'uri' => $uri,
            ],
        ];
        if ($finding->line !== null) {
            $region = [
                'startLine' => $finding->line,
            ];
            if ($finding->column !== null) {
                $region['startColumn'] = $finding->column;
            }
            if ($finding->endLine !== null) {
                $region['endLine'] = $finding->endLine;
            }
            $physicalLocation['region'] = $region;
        }

        $properties = [
            'severity' => $finding->severity->value,
            'pillar' => $finding->pillar->value,
            'tier' => $finding->tier->value,
            'confidence' => $finding->confidence->value,
        ];
        if ($finding->secondaryPillars !== []) {
            $properties['secondaryPillars'] = array_map(
                static fn (Pillar $pillar): string => $pillar->value,
                $finding->secondaryPillars,
            );
        }
        if ($finding->symbol !== null) {
            $properties['symbol'] = $finding->symbol;
        }
        if ($finding->remediation !== null) {
            $properties['remediation'] = $finding->remediation;
        }
        if ($finding->metadata !== []) {
            $properties['metadata'] = $finding->metadata;
        }

        // partialFingerprints carries the gruff fingerprint so SARIF tooling can track a finding across line drift.
        return [
            'ruleId' => $finding->ruleId,
            'ruleIndex' => $ruleIndex,
            'level' => $this->level($finding->severity),
            'message' => [
                'text' => $finding->message,
            ],
            'locations' => [[
                'physicalLocation' => $physicalLocation,
            ]],
            'partialFingerprints' => [
                'gruffFingerprint' => $finding->fingerprint(),
            ],
            'properties' => $properties,
        ];
    }

    /**
     * Map gruff-php severities onto SARIF result levels.
     *
     * @param Severity $severity Gruff severity to translate; advisory collapses to SARIF `note`, which has no peer.
     * @return string SARIF level name.
     */
    private function level(Severity $severity): string
    {
        // SARIF defines no "advisory" level, so gruff's advisory maps onto its closest peer, `note`.
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
            Severity::Advisory => 'note',
        };
    }
}
