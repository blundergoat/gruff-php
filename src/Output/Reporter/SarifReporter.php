<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\RuleRegistry;
use JsonException;

/**
 * Renders a finished analysis run as a SARIF 2.1.0 JSON document - the format GitHub Code Scanning
 * and similar platforms ingest to show findings inline on a pull request and in the Security tab.
 *
 * Reach for this when the run's consumer is a code-scanning platform rather than a person reading the
 * terminal: the user asks for it with `gruff-php analyse --format sarif`, redirects it to a `.sarif`
 * file, and uploads that file so each finding lands as an annotation on the line it flags. It sits
 * beside the other `OutputFormat` renderers (text, JSON, HTML, Markdown, hotspot, GitHub) as the code-scanning
 * option, and stamps every result with two fingerprints so an alert survives unrelated line drift.
 */
final readonly class SarifReporter
{
    /**
     * Turns the whole finished run into the single SARIF string `analyse --format sarif` prints, so a
     * code-scanning upload receives every rule and finding - with line locations and fingerprints - in
     * one document instead of the human-readable terminal report.
     *
     * @param AnalysisReport $report - Completed analysis run whose rules and findings become the SARIF body.
     *
     * @return string - the SARIF 2.1.0 JSON document with trailing newline; on encode failure a minimal JSON
     *                  error object instead, so the caller always gets parseable output rather than an exception
     */
    public function render(AnalysisReport $report): string
    {
        $rules = [];
        // Seed the SARIF rule catalogue with every built-in rule, so a Code Scanning alert can always
        // link back to a full rule description even for rules this run never tripped.
        foreach (RuleRegistry::defaults()->all() as $rule) {
            $definition             = $rule->definition();
            $rules[$definition->id] = $this->rule($definition);
        }

        // Backfill a minimal rule entry for any finding whose rule isn't built-in - the `??=` leaves the
        // richer definitions from the first loop untouched, so no result points at a rule we never list.
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
        // Publish the composite score and grade only when the run produced them; a null score means
        // scoring was skipped, so we omit the fields rather than show a misleading zero.
        if ($report->score !== null) {
            $properties['score'] = $report->score->composite->score;
            $properties['grade'] = $report->score->composite->letter;
        }

        $run = [
            'tool' => [
                'driver' => [
                    'name' => AnalysisReport::TOOL_NAME,
                    'semanticVersion' => $report->toolVersion,
                    'rules' => array_values($rules),
                ],
            ],
            'results' => array_map(fn(Finding $finding): array => $this->result($finding, (int)$ruleIndexes[$finding->ruleId]), $report->findings),
            'properties' => $properties,
        ];
        if ($report->diagnostics !== []) {
            $run['invocations'] = [[
                'executionSuccessful' => !array_reduce(
                    $report->diagnostics,
                    static fn (bool $fatal, RunDiagnostic $diagnostic): bool => $fatal || $diagnostic->isFatal,
                    false,
                ),
                'toolExecutionNotifications' => array_map(
                    fn (RunDiagnostic $diagnostic): array => $this->notification($diagnostic),
                    $report->diagnostics,
                ),
            ]];
        }

        $payload = [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [$run],
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
     * Serialises one run diagnostic as a SARIF tool-execution notification.
     *
     * @return array<string, mixed> - SARIF notification with an optional physical location.
     */
    private function notification(RunDiagnostic $diagnostic): array
    {
        $notification = [
            'descriptor' => ['id' => $diagnostic->type],
            'level' => $diagnostic->isFatal ? 'error' : 'note',
            'message' => ['text' => $diagnostic->message],
        ];
        $filePath = $diagnostic->filePath ?? $diagnostic->path;
        if ($filePath !== null) {
            $physicalLocation = ['artifactLocation' => ['uri' => str_replace('\\', '/', $filePath)]];
            if ($diagnostic->line !== null) {
                $physicalLocation['region'] = ['startLine' => $diagnostic->line];
            }
            $notification['locations'] = [['physicalLocation' => $physicalLocation]];
        }

        return $notification;
    }

    /**
     * Describes one registered rule as a SARIF driver rule, so every check gruff can emit appears in the
     * report's rule catalogue with its pillar, tier, and threshold - the metadata a Code Scanning UI
     * shows beside each alert. Called once per rule while `render()` builds that catalogue.
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
            'pillar' => $definition->pillar->value,
            'tier' => $definition->tier->value,
            'defaultSeverity' => $definition->defaultSeverity->value,
            'confidence' => $definition->confidence->value,
            'defaultEnabled' => $definition->isEnabledByDefault,
        ];
        // Some rules also feed secondary pillars; list them when present so a consumer sees every
        // quality area the rule touches. The common empty case maps to a single pillar and omits the key.
        if ($definition->secondaryPillars !== []) {
            $properties['secondaryPillars'] = array_map(
                static fn(Pillar $pillar): string => $pillar->value,
                $definition->secondaryPillars,
            );
        }
        $single = $definition->severityThreshold;
        // A rule with a single threshold-plus-severity (size, complexity, and the like) publishes its
        // default threshold and the severity label it trips at, so a reviewer sees the rule's configured limit.
        if ($single instanceof \GruffPhp\Engine\Config\SeverityThreshold) {
            $properties['threshold'] = $single->threshold;
            $properties['severity']  = $single->severity->value;
        // A rule that instead exposes a map of named thresholds passes that map straight through.
        } elseif ($definition->defaultThresholds !== []) {
            $properties['thresholds'] = $definition->defaultThresholds;
        }
        // When a rule ships tunable options, expose their defaults so a reader can see what's
        // configurable; a rule with none omits the key entirely.
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
     * Turns one finding into a single SARIF result - the object that becomes an annotation on a pull
     * request line. Called once per finding while `render()` assembles the run's results list.
     *
     * @param Finding $finding   - Finding to serialize into a single SARIF result entry.
     * @param int     $ruleIndex - Zero-based offset of this finding's rule in the driver `rules` array, so the
     *                           result can reference its rule by index rather than repeating the descriptor.
     *
     * @return array<string, mixed> - one SARIF `result` entry with `region` keys only when the finding has line/column data, plus two
     *                       `partialFingerprints`: the precise `gruffFingerprint` and the line-insensitive `gruffStableIdentity`, so a
     *                       Code Scanning alert stays open across line drift instead of closing and reopening on the user
     */
    private function result(Finding $finding, int $ruleIndex): array
    {
        // SARIF wants forward-slash, repo-relative paths, so flip Windows separators and strip any
        // leading `./` - otherwise Code Scanning would annotate the wrong path, or none at all.
        $uri = str_replace('\\', '/', $finding->filePath);
        $uri = (string)preg_replace('/^(?:\\.\\/)+/', '', $uri);

        $physicalLocation = [
            'artifactLocation' => [
                'uri' => $uri,
            ],
        ];
        // A line-anchored finding gets a region so the alert lands on the exact spot; a null line is a
        // file-level finding, left without a region so it attaches to the file as a whole.
        if ($finding->line !== null) {
            $region = [
                'startLine' => $finding->line,
            ];
            // Narrow the highlight to a column when the rule pinpointed one, rather than the line start.
            if ($finding->column !== null) {
                $region['startColumn'] = $finding->column;
            }
            // Stretch the region to an end line for a multi-line finding, so the highlight covers the
            // whole offending block instead of only its first line.
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
        // Carry any extra pillars this finding touches, so a Code Scanning reader sees every quality
        // area it affects; the empty case belongs to just its primary pillar.
        if ($finding->secondaryPillars !== []) {
            $properties['secondaryPillars'] = array_map(
                static fn(Pillar $pillar): string => $pillar->value,
                $finding->secondaryPillars,
            );
        }
        // Attach the named symbol (class, method, …) the finding is about when there is one, giving the
        // alert a human anchor; a null symbol means the finding isn't tied to a specific name.
        if ($finding->symbol !== null) {
            $properties['symbol'] = $finding->symbol;
        }
        // Include the remediation hint when the rule offers one, so the alert tells the user how to fix
        // it; null means this finding ships no canned advice.
        if ($finding->remediation !== null) {
            $properties['remediation'] = $finding->remediation;
        }
        // Attach rule-specific extras (measured values, limits, names, candidates) when present, so the alert
        // can show the detail behind the verdict; an empty map means the finding needs no extra context.
        if ($finding->metadata !== []) {
            $properties['metadata'] = $finding->metadata;
        }

        // Two fingerprints: the precise one moves with the line, the stable one survives line drift.
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
                'gruffStableIdentity' => $finding->stableIdentity(),
            ],
            'properties' => $properties,
        ];
    }

    /**
     * Translates a gruff severity into the SARIF level name a Code Scanning UI colours by, so an error
     * shows as an error and an advisory as the quieter `note`. Called for every result `render()` emits.
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
