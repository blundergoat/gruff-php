<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\RuleRegistry;
use JsonException;

/**
 * Renders analysis reports in SARIF format.
 */
final readonly class SarifReporter
{
    /**
     * Render findings as a SARIF 2.1.0 JSON document.
     *
      * User flow: Shapes the report output people read after analysis finishes.
      *
     * @param AnalysisReport $report - Analysis report to render.
     *
     * @return string - the SARIF 2.1.0 JSON document with trailing newline; on encode failure a minimal JSON
     *                  error object instead, so the caller always gets parseable output rather than an exception
     */
    public function render(AnalysisReport $report): string
    {
        $rules = [];
        // User view: add each item that can appear in report output.
        foreach (RuleRegistry::defaults()->all() as $rule) {
            $definition             = $rule->definition();
            $rules[$definition->id] = $this->rule($definition);
        }

        // User view: add each item that can appear in report output.
        foreach ($report->findings as $finding) {
            $rules[$finding->ruleId] ??= [
                'id'               => $finding->ruleId,
                'name'             => $finding->ruleId,
                'shortDescription' => [
                    'text' => $finding->ruleId,
                ],
                'properties'       => [
                    'pillar'     => $finding->pillar->value,
                    'severity'   => $finding->severity->value,
                    'confidence' => $finding->confidence->value,
                    'tier'       => $finding->tier->value,
                ],
            ];
        }

        ksort($rules, SORT_STRING);
        $ruleIds     = array_keys($rules);
        $ruleIndexes = array_flip($ruleIds);
        $properties  = [
            'gruffSchemaVersion' => AnalysisReport::SCHEMA_VERSION,
        ];
        // User view: choose the report output branch for this case.
        // User view: missing data becomes the expected report output state.
        if ($report->score !== null) {
            $properties['score'] = $report->score->composite->score;
            $properties['grade'] = $report->score->composite->letter;
        }

        $payload = [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs'    => [[
                              'tool'       => [
                                  'driver' => [
                                      'name'            => AnalysisReport::TOOL_NAME,
                                      'semanticVersion' => $report->toolVersion,
                                      'rules'           => array_values($rules),
                                  ],
                              ],
                              'results'    => array_map(fn(Finding $finding): array => $this->result($finding, (int)$ruleIndexes[$finding->ruleId]), $report->findings),
                              'properties' => $properties,
                          ]],
        ];

        try {
            // Trailing newline keeps redirected .sarif files POSIX-clean; JSON_INVALID_UTF8_SUBSTITUTE swaps
            // bad source bytes for U+FFFD so the user's Code Scanning upload never dies on one weird byte.
            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (JsonException $exception) {
            // Encoding failure still emits parseable JSON so a SARIF consumer reports an error instead of choking.
            return sprintf('{"error":"Unable to encode SARIF: %s"}%s', addslashes($exception->getMessage()), PHP_EOL);
        }
    }

    /**
     * Render one registry definition as a SARIF driver rule.
     *
      * User flow: Shapes the report output people read after analysis finishes.
      *
     * @param RuleDefinition $definition - Native rule definition.
     *
     * @return array{
     *     id: string,
     *     name: string,
     *     shortDescription: array{text: string},
     *     fullDescription: array{text: string},
     *     help: array{text: string},
     *     properties: array<string, bool|float|int|string|array<array-key, bool|float|int|string|array<array-key, bool|float|int|string>>>
     * } - one SARIF `reportingDescriptor` for the driver `rules` list; `help`/`fullDescription` duplicate the one-line rule description, and
     * `properties` carries gruff-specific metadata (pillar, tier, threshold) only when set
     */
    private function rule(RuleDefinition $definition): array
    {
        $properties = [
            'pillar'          => $definition->pillar->value,
            'tier'            => $definition->tier->value,
            'defaultSeverity' => $definition->defaultSeverity->value,
            'confidence'      => $definition->confidence->value,
            'defaultEnabled'  => $definition->isEnabledByDefault,
        ];
        // User view: choose the report output branch for this case.
        // User view: an empty value becomes a clear report output fallback.
        if ($definition->secondaryPillars !== []) {
            $properties['secondaryPillars'] = array_map(
                static fn(Pillar $pillar): string => $pillar->value,
                $definition->secondaryPillars,
            );
        }
        $single = $definition->severityThreshold;
        // User view: choose the report output branch for this case.
        if ($single instanceof \GruffPhp\Engine\Config\SeverityThreshold) {
            $properties['threshold'] = $single->threshold;
            $properties['severity']  = $single->severity->value;
        }
        // User view: an empty value becomes a clear report output fallback.
        // User view: choose the next report output branch for this case.
        elseif ($definition->defaultThresholds !== []) {
            $properties['thresholds'] = $definition->defaultThresholds;
        }
        // User view: choose the report output branch for this case.
        // User view: an empty value becomes a clear report output fallback.
        if ($definition->defaultOptions !== []) {
            $properties['options'] = $definition->defaultOptions;
        }

        // Help and full description reuse the one-line rule description; gruff has no separate long-form help text.
        return [
            'id'               => $definition->id,
            'name'             => $definition->name,
            'shortDescription' => [
                'text' => $definition->name,
            ],
            'fullDescription'  => [
                'text' => $definition->description(),
            ],
            'help'             => [
                'text' => $definition->description(),
            ],
            'properties'       => $properties,
        ];
    }

    /**
     * Build one SARIF result payload for a finding.
     *
      * User flow: Shapes the report output people read after analysis finishes.
      *
     * @param Finding $finding - Finding to serialize into a single SARIF result entry.
     * @param int     $ruleIndex - Zero-based offset of this finding's rule in the driver `rules` array, so the
     *                           result can reference its rule by index rather than repeating the descriptor.
     *
     * @return array<string, mixed> - one SARIF `result` entry with `region` keys only when the finding has line/column data, plus two
     *                       `partialFingerprints`: the precise `gruffFingerprint` and the line-insensitive `gruffStableIdentity`, so a
     *                       Code Scanning alert stays open across line drift instead of closing and reopening on the user
     */
    private function result(Finding $finding, int $ruleIndex): array
    {
        $uri = str_replace('\\', '/', $finding->filePath);
        $uri = (string)preg_replace('/^(?:\\.\\/)+/', '', $uri);

        $physicalLocation = [
            'artifactLocation' => [
                'uri' => $uri,
            ],
        ];
        // User view: choose the report output branch for this case.
        // User view: missing data becomes the expected report output state.
        if ($finding->line !== null) {
            $region = [
                'startLine' => $finding->line,
            ];
            // User view: choose the report output branch for this case.
            // User view: missing data becomes the expected report output state.
            if ($finding->column !== null) {
                $region['startColumn'] = $finding->column;
            }
            // User view: choose the report output branch for this case.
            // User view: missing data becomes the expected report output state.
            if ($finding->endLine !== null) {
                $region['endLine'] = $finding->endLine;
            }
            $physicalLocation['region'] = $region;
        }

        $properties = [
            'severity'   => $finding->severity->value,
            'pillar'     => $finding->pillar->value,
            'tier'       => $finding->tier->value,
            'confidence' => $finding->confidence->value,
        ];
        // User view: choose the report output branch for this case.
        // User view: an empty value becomes a clear report output fallback.
        if ($finding->secondaryPillars !== []) {
            $properties['secondaryPillars'] = array_map(
                static fn(Pillar $pillar): string => $pillar->value,
                $finding->secondaryPillars,
            );
        }
        // User view: choose the report output branch for this case.
        // User view: missing data becomes the expected report output state.
        if ($finding->symbol !== null) {
            $properties['symbol'] = $finding->symbol;
        }
        // User view: choose the report output branch for this case.
        // User view: missing data becomes the expected report output state.
        if ($finding->remediation !== null) {
            $properties['remediation'] = $finding->remediation;
        }
        // User view: choose the report output branch for this case.
        // User view: an empty value becomes a clear report output fallback.
        if ($finding->metadata !== []) {
            $properties['metadata'] = $finding->metadata;
        }

        // Two fingerprints: the precise one moves with the line, the stable one survives line drift.
        return [
            'ruleId'              => $finding->ruleId,
            'ruleIndex'           => $ruleIndex,
            'level'               => $this->level($finding->severity),
            'message'             => [
                'text' => $finding->message,
            ],
            'locations'           => [[
                                          'physicalLocation' => $physicalLocation,
                                      ]],
            'partialFingerprints' => [
                'gruffFingerprint'    => $finding->fingerprint(),
                'gruffStableIdentity' => $finding->stableIdentity(),
            ],
            'properties'          => $properties,
        ];
    }

    /**
     * Map gruff-php severities onto SARIF result levels.
     *
      * User flow: Shapes the report output people read after analysis finishes.
      *
     * @param Severity $severity - Gruff severity to translate; advisory collapses to SARIF `note`, which has no peer.
     *
     * @return string - one of SARIF's `error`/`warning`/`note` level names; advisory collapses to `note`
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
