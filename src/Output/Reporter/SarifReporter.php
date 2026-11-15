<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Finding\BaselineIdentity;
use GruffPhp\Results\Finding\DeclarationInventory;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\RuleRegistry;
use JsonException;

/**
 * Renders an analysis report as SARIF 2.1.0 JSON for code-scanning consumers.
 * Use `gruff-php analyse --format sarif` to produce a report for upload.
 * Eligible results carry one baseline fingerprint; sensitive findings and symbols containing the ordinal separator receive none.
 */
final readonly class SarifReporter
{
    /**
     * Keeps the root the findings' paths resolve against, so a variable's fingerprint is ranked against the scanned source as the baseline ranks it.
     *
     * @param string|null $projectRoot - Project root of the run; null ranks a variable among this document's findings alone.
     */
    public function __construct(private ?string $projectRoot = null)
    {
    }

    /**
     * Builds the SARIF document from registered rules, reported and suppressed findings, and run diagnostics.
     * Results include available line locations and eligible baseline fingerprints.
     *
     * @param AnalysisReport $report - Completed analysis run whose rules and findings become the SARIF body.
     *
     * @return string - SARIF JSON plus a newline, or a minimal JSON error object if encoding raises JsonException.
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

        // Backfill a minimal rule entry for any finding whose rule isn't built-in - the ??= operator leaves the
        // richer definitions from the first loop untouched, so no result points at a rule we never list.
        $rendered = [...$report->findings, ...array_column($report->suppressedFindings(), 'finding')];
        // Rank once over every finding this document carries, as the baseline ranks its run; ranking one finding
        // at a time would give every occurrence of a variable symbol ordinal 1 and merge their code-scanning alerts.
        $ordinals = BaselineIdentity::assignOrdinals(
            $rendered,
            $this->projectRoot === null ? null : DeclarationInventory::forFindings($this->projectRoot, $rendered),
        );
        foreach ($rendered as $finding) {
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
        // Omit unavailable composite scores so consumers do not mistake missing grades for zero.

        if ($report->score?->composite !== null) {
            $properties['score'] = $report->score->composite->score;
            $properties['grade'] = $report->score->composite->letter;
        }

        $sarifRun = [
            'tool' => [
                'driver' => [
                    'name' => AnalysisReport::TOOL_NAME,
                    'semanticVersion' => $report->toolVersion,
                    'rules' => array_values($rules),
                ],
            ],
            'results' => [
                ...array_map(fn(Finding $finding): array => $this->result($finding, (int)$ruleIndexes[$finding->ruleId], $ordinals), $report->findings),
                // A finding another tool's comment or a repeated claim removed stays a result marked suppressed, so a
                // code-scanning tool shows it as suppressed rather than fixed (FAMILY-CONTRACT.md section 13c).
                ...array_map(
                    fn(array $item): array => [
                        ...$this->result($item['finding'], (int)$ruleIndexes[$item['finding']->ruleId], $ordinals),
                        'suppressions' => [['kind' => 'inSource', 'justification' => $item['justification']]],
                    ],
                    $report->suppressedFindings(),
                ),
            ],
            'properties' => $properties,
        ];
        if ($report->diagnostics !== []) {
            $sarifRun['invocations'] = [[
                'executionSuccessful' => !array_reduce(
                    $report->diagnostics,
                    static fn (bool $isFatalSoFar, RunDiagnostic $diagnostic): bool => $isFatalSoFar || $diagnostic->isFatal,
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
            'runs' => [$sarifRun],
        ];

        try {
            // Trailing newline keeps redirected .sarif files POSIX-clean; JSON_INVALID_UTF8_SUBSTITUTE swaps
            // invalid source bytes for U+FFFD so one invalid byte does not block the user's Code Scanning upload.
            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (JsonException $exception) {

            return sprintf('{"error":"Unable to encode SARIF: %s"}%s', addslashes($exception->getMessage()), PHP_EOL);
        }
    }

    /**
     * Serialises one run diagnostic as a SARIF tool-execution notification.
     *
     * @param RunDiagnostic $diagnostic - Run-level note to serialise; a fatal one raises the notification level.
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
     * Builds a SARIF driver descriptor from a built-in rule's names, description, and default settings.
     * render() uses these descriptors for the rule catalogue and supplies minimal entries for other rule IDs.
     *
     * @param RuleDefinition $definition - Built-in rule whose names, description, and default settings populate the descriptor.
     *
     * @return array{
     *     id: string,
     *     name: string,
     *     shortDescription: array{text: string},
     *     fullDescription: array{text: string},
     *     help: array{text: string},
     *     properties: array<string, bool|float|int|string|array<array-key, bool|float|int|string|array<array-key, bool|float|int|string>>>
     * } - SARIF rule descriptor with names, descriptions, and required and optional gruff metadata.
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
                static fn(Pillar $pillar): string => $pillar->value,
                $definition->secondaryPillars,
            );
        }
        $single = $definition->severityThreshold;
        // These thresholds are registry defaults, not the effective settings for this run.

        if ($single instanceof \GruffPhp\Engine\Config\SeverityThreshold) {
            $properties['threshold'] = $single->threshold;
            $properties['severity']  = $single->severity->value;

        } elseif ($definition->defaultThresholds !== []) {
            $properties['thresholds'] = $definition->defaultThresholds;
        }


        if ($definition->defaultOptions !== []) {
            $properties['options'] = $definition->defaultOptions;
        }


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
     * Builds one SARIF result with a location, rule reference, and gruff metadata.
     * Line and column regions are included only when the finding provides the required location data.
     *
     * @param Finding         $finding   - Finding to serialize into a single SARIF result entry.
     * @param int             $ruleIndex - Zero-based offset of the finding's rule in the driver rules array.
     * @param array<int, int> $ordinals  - Declaration ordinals from BaselineIdentity::assignOrdinals(), ranked over the whole document.
     *
     * @return array<string, mixed> - SARIF result with a file location, optional region, gruff metadata, and eligible baseline fingerprint.
     */
    private function result(Finding $finding, int $ruleIndex, array $ordinals): array
    {
        // SARIF wants forward-slash, repo-relative paths, so flip Windows separators and strip any
        // leading ./ prefixes; otherwise Code Scanning could annotate the wrong path or no path at all.
        $uri = str_replace('\\', '/', $finding->filePath);
        // Repeated relative prefixes still name the same repository path, so normalize them before publishing the SARIF location.
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
                static fn(Pillar $pillar): string => $pillar->value,
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
            'properties' => $properties,
        ] + $this->partialFingerprints($finding, $ordinals);
    }

    /**
     * Returns the baseline fingerprint fields to merge into a SARIF result.
     * Sensitive findings and symbols containing the ordinal separator have no durable identity.
     * Eligible fingerprints use the supplied declaration ordinal to distinguish repeated variable symbols.
     *
     * @param Finding         $finding  - Finding being rendered as a SARIF result.
     * @param array<int, int> $ordinals - Declaration ordinals ranked over the whole document, keyed by spl_object_id().
     *
     * @return array{partialFingerprints?: array{gruffFingerprint: string}} - Fingerprint fields, or an empty array when identity is forbidden.
     */
    private function partialFingerprints(Finding $finding, array $ordinals): array
    {
        // A secret is never given a durable name, in this port or in the external system reading its report.
        // A symbol carrying the ordinal separator has no identity either, so it publishes no fingerprint.
        if (!BaselineIdentity::hasIdentity($finding)) {
            return [];
        }

        return ['partialFingerprints' => ['gruffFingerprint' => BaselineIdentity::identityOf($finding, $ordinals[spl_object_id($finding)] ?? 1)]];
    }

    /**
     * Translates a gruff severity into the SARIF level used by Code Scanning, where advisory findings
     * appear at SARIF's quieter note level. Called for every result emitted by render().
     *
     * @param Severity $severity - Finding severity; advisory maps to SARIF's note level.
     *
     * @return string - SARIF error, warning, or note level; advisory maps to note.
     */
    private function level(Severity $severity): string
    {
        // SARIF defines no advisory level, so gruff's advisory maps to its closest level, note.
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
            Severity::Advisory => 'note',
        };
    }
}
