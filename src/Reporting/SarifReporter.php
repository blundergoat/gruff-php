<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Severity;
use JsonException;

/**
 * Renders analysis reports in SARIF format.
 */
final readonly class SarifReporter
{
    /**
     * Render findings as a SARIF 2.1.0 JSON document.
     *
     * @return string SARIF JSON document or encoded error payload.
     */
    public function render(AnalysisReport $report): string
    {
        $rules = [];
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
        $ruleIds = array_keys($rules);
        $ruleIndexes = array_flip($ruleIds);

        $payload = [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [[
                'tool' => [
                    'driver' => [
                        'name' => 'gruff',
                        'version' => $report->toolVersion,
                        'informationUri' => 'https://github.com/',
                        'rules' => array_values($rules),
                    ],
                ],
                'results' => array_map(fn (Finding $finding): array => $this->result($finding, (int) $ruleIndexes[$finding->ruleId]), $report->findings),
            ]],
        ];

        try {
            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (JsonException $exception) {
            return sprintf('{"error":"Unable to encode SARIF: %s"}%s', addslashes($exception->getMessage()), PHP_EOL);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Finding $finding, int $ruleIndex): array
    {
        return [
            'ruleId' => $finding->ruleId,
            'ruleIndex' => $ruleIndex,
            'level' => $this->level($finding->severity),
            'message' => [
                'text' => $finding->message,
            ],
            'locations' => [[
                'physicalLocation' => [
                    'artifactLocation' => [
                        'uri' => $finding->filePath,
                    ],
                    'region' => [
                        'startLine' => $finding->line ?? 1,
                    ],
                ],
            ]],
            'partialFingerprints' => [
                'gruffFingerprint' => $finding->fingerprint(),
            ],
            'properties' => [
                'symbol' => $finding->symbol,
                'pillar' => $finding->pillar->value,
                'metadata' => $finding->metadata,
            ],
        ];
    }

    /**
     * Map gruff severities onto SARIF result levels.
     *
     * @return string SARIF level name.
     */
    private function level(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
            Severity::Advisory => 'note',
        };
    }
}
