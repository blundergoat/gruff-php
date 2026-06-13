<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\RuleRegistry;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints configured rule metadata for humans or tooling, with an optional
 * per-rule detail view when a `<ruleId>` argument is supplied.
 */
final class ListRulesCommand extends Command
{
    /**
     * Maximum Levenshtein distance to consider when suggesting a near-match
     * for a mistyped rule id.
     */
    private const SUGGESTION_DISTANCE = 4;

    /**
     * Register list-rules CLI options and metadata.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('list-rules')
            ->setDescription('List gruff-php rule metadata; pass a rule id argument for a per-rule detail view.')
            ->addArgument('ruleId', InputArgument::OPTIONAL, 'Stable rule identifier. When supplied, renders a per-rule detail view instead of the catalogue.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text, table, or json.', default: 'table');
    }

    /**
     * Render rule metadata as either a table or JSON document, or a per-rule detail view.
     *
     * @param InputInterface  $input - Parsed invocation; supplies the `format` option and optional `ruleId` argument.
     * @param OutputInterface $output - Destination the rendered catalogue, detail view, or error is written to.
     *
     * @return int - Symfony exit code: SUCCESS once output is written, INVALID for a bad format, FAILURE if JSON encoding fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!is_string($format) || !in_array($format, ['text', 'table', 'json'], true)) {
            $output->writeln('<error>USAGE-ERROR Unsupported rule-list format. Use text, table, or json.</error>');

            return Command::INVALID;
        }

        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $ruleId   = $input->getArgument('ruleId');

        if (is_string($ruleId) && $ruleId !== '') {
            return $this->renderRuleDetail(
                ruleId:   $ruleId,
                registry: $registry,
                config:   $config,
                format:   $format,
                output:   $output,
            );
        }

        /** @var list<array{id: string, name: string, pillar: string, tier: string, defaultSeverity: string, confidence: string, defaultEnabled: bool, thresholds: array<string, int|float>|\stdClass, options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass, description: string}> $rows Accumulator shape is built from rule definitions for table rendering. */
        $rows = [];

        foreach ($registry->all() as $rule) {
            $definition = $rule->definition();
            $settings   = $config->ruleSettings($definition->id);
            $rows[]     = $this->ruleMetadataRow($definition, $settings->enabled);
        }

        if ($format === 'json') {
            try {
                $output->write(json_encode(['rules' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, false, OutputInterface::OUTPUT_RAW);
            } catch (JsonException $exception) {
                $output->writeln(sprintf('<error>Unable to encode rule metadata: %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $output->writeln('Rule ID | Pillar | Tier | Severity | Confidence | Enabled | Description');
        $output->writeln('--- | --- | --- | --- | --- | --- | ---');

        foreach ($rows as $ruleMetadata) {
            $output->writeln(sprintf(
                                 '%s | %s | %s | %s | %s | %s | %s',
                                 $ruleMetadata['id'],
                                 $ruleMetadata['pillar'],
                                 $ruleMetadata['tier'],
                                 $ruleMetadata['defaultSeverity'],
                                 $ruleMetadata['confidence'],
                                 $ruleMetadata['defaultEnabled'] ? 'yes' : 'no',
                                 $ruleMetadata['description'],
                             ));
        }

        return Command::SUCCESS;
    }

    /**
     * Render the per-rule detail view, or report a typo with near-match suggestions.
     *
     * @param string          $ruleId - Rule id the caller asked to inspect; matched exactly against the registry.
     * @param RuleRegistry    $registry - Source of the canonical rule set the lookup and typo suggestions draw from.
     * @param AnalysisConfig  $config - Effective config supplying whether the matched rule is enabled for this project.
     * @param string          $format - Pre-validated output format (`text`, `table`, or `json`) selecting the renderer.
     * @param OutputInterface $output - Destination the detail view or unknown-rule message is written to.
     *
     * @return int - Symfony exit code: SUCCESS once the detail view is written, FAILURE if JSON encoding fails; an unknown id defers to the
     *             not-found path's INVALID
     */
    private function renderRuleDetail(string $ruleId, RuleRegistry $registry, AnalysisConfig $config, string $format, OutputInterface $output): int
    {
        $match = null;
        foreach ($registry->all() as $rule) {
            $definition = $rule->definition();
            if ($definition->id === $ruleId) {
                $match = $definition;
                break;
            }
        }

        if ($match === null) {
            return $this->renderRuleNotFound($ruleId, $registry, $output);
        }

        $enabled = $config->ruleSettings($match->id)->enabled;

        if ($format === 'json') {
            try {
                $output->write(json_encode($this->ruleDetailPayload($match, $enabled), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, false, OutputInterface::OUTPUT_RAW);
            } catch (JsonException $exception) {
                $output->writeln(sprintf('<error>Unable to encode rule detail: %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $output->write($this->renderDetailText($match, $enabled), false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    /**
     * Print a friendly typo response with up to three near-match suggestions, exit INVALID.
     *
     * @param string          $ruleId - Unrecognised rule id the caller typed; echoed back and used as the match anchor.
     * @param RuleRegistry    $registry - Source of known rule ids the Levenshtein suggestions are drawn from.
     * @param OutputInterface $output - Destination the unknown-rule error and any suggestions are written to.
     *
     * @return int - always Symfony INVALID, signalling a bad rule-id argument so callers can branch on misuse
     */
    private function renderRuleNotFound(string $ruleId, RuleRegistry $registry, OutputInterface $output): int
    {
        $output->writeln(sprintf('<error>Unknown rule: %s.</error>', $ruleId));

        $candidates = [];
        foreach ($registry->all() as $rule) {
            $candidateId              = $rule->definition()->id;
            $candidates[$candidateId] = levenshtein($ruleId, $candidateId);
        }

        asort($candidates);
        $suggestions = [];
        foreach ($candidates as $candidateId => $distance) {
            if ($distance > self::SUGGESTION_DISTANCE) {
                continue;
            }
            $suggestions[] = $candidateId;
            if (count($suggestions) === 3) {
                break;
            }
        }

        if ($suggestions !== []) {
            $output->writeln(sprintf('Did you mean: %s ?', implode(', ', $suggestions)));
        }

        return Command::INVALID;
    }

    /**
     * Serialise the per-rule detail payload for JSON output.
     *
     * @param RuleDefinition $definition - Rule whose metadata, thresholds, options, and escape hatches are serialised.
     * @param bool           $enabled - Effective project enabled state; emitted as the `defaultEnabled` field.
     *
     * @return array - JSON-ready detail document; empty option/threshold maps are stdClass so they
     *                   encode as `{}` rather than `[]`
     * @phpstan-return array{
     *     id: string,
     *     name: string,
     *     pillar: string,
     *     tier: string,
     *     defaultSeverity: string,
     *     confidence: string,
     *     defaultEnabled: bool,
     *     description: string,
     *     thresholds: array<string, int|float|string>|\stdClass,
     *     options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass,
     *     optionDescriptions: array<string, string>|\stdClass,
     *     escapeHatches: list<array{path: string, description: string}>,
     *     falsePositiveShapes: list<array{shape: string, mitigation: string}>
     * }
     */
    private function ruleDetailPayload(RuleDefinition $definition, bool $enabled): array
    {
        $single     = $definition->severityThreshold;
        $thresholds = $single instanceof \GruffPhp\Engine\Config\SeverityThreshold
            ? ['threshold' => $single->threshold, 'severity' => $single->severity->value]
            : ($definition->defaultThresholds === [] ? (object)[] : $definition->defaultThresholds);

        // Empty maps become stdClass above so JSON encodes them as `{}` not `[]`, keeping the schema object-typed.
        return [
            'id'                  => $definition->id,
            'name'                => $definition->name,
            'pillar'              => $definition->pillar->value,
            'tier'                => $definition->tier->value,
            'defaultSeverity'     => $definition->defaultSeverity->value,
            'confidence'          => $definition->confidence->value,
            'defaultEnabled'      => $enabled,
            'description'         => $definition->description(),
            'thresholds'          => $thresholds,
            'options'             => $definition->defaultOptions === [] ? (object)[] : $definition->defaultOptions,
            'optionDescriptions'  => $definition->optionDescriptions === [] ? (object)[] : $definition->optionDescriptions,
            'escapeHatches'       => $this->escapeHatchesFor($definition),
            'falsePositiveShapes' => $definition->falsePositiveShapes,
        ];
    }

    /**
     * Render a per-rule detail view as multi-line text.
     *
     * @param RuleDefinition $definition - Rule whose name, pillar, options, hatches, and false-positive shapes render.
     * @param bool           $enabled - Effective project enabled state; printed on the "Enabled by default" line.
     *
     * @return string - the full multi-line detail block, newline-joined and terminated with a trailing newline so it writes cleanly raw
     */
    private function renderDetailText(RuleDefinition $definition, bool $enabled): string
    {
        $lines   = [];
        $lines[] = sprintf('Rule: %s', $definition->id);
        $lines[] = sprintf('  Name:               %s', $definition->name);
        $lines[] = sprintf('  Pillar:             %s', $definition->pillar->value);
        $lines[] = sprintf('  Tier:               %s', $definition->tier->value);
        $lines[] = sprintf('  Severity:           %s (default)', $definition->defaultSeverity->value);
        $lines[] = sprintf('  Confidence:         %s', $definition->confidence->value);
        $lines[] = sprintf('  Enabled by default: %s', $enabled ? 'yes' : 'no');

        $lines[] = '';
        $lines[] = 'Description:';
        $lines[] = '  ' . $definition->description();

        if ($definition->defaultOptions !== []) {
            $lines[]  = '';
            $lines[]  = 'Default options:';
            $keyWidth = max(array_map('strlen', array_keys($definition->defaultOptions)));
            foreach ($definition->defaultOptions as $key => $defaultValue) {
                $valueRender = $this->formatOptionValue($defaultValue);
                $description = $definition->optionDescriptions[$key] ?? '';
                $lines[]     = sprintf(
                    '  %-' . $keyWidth . 's  %s%s',
                    $key,
                    $valueRender,
                    $description === '' ? '' : '  ' . $description,
                );
            }
        }

        $hatches = $this->escapeHatchesFor($definition);
        if ($hatches !== []) {
            $lines[]   = '';
            $lines[]   = 'Escape hatches:';
            $pathWidth = max(array_map(static fn(array $escapeHatch): int => strlen($escapeHatch['path']), $hatches));
            foreach ($hatches as $hatch) {
                $lines[] = sprintf(
                    '  %-' . $pathWidth . 's  %s',
                    $hatch['path'],
                    $hatch['description'],
                );
            }
        }

        if ($definition->falsePositiveShapes !== []) {
            $lines[] = '';
            $lines[] = 'Common false-positive shapes:';
            foreach ($definition->falsePositiveShapes as $entry) {
                $lines[] = '  - ' . $entry['shape'];
                $lines[] = '    Mitigation: ' . $entry['mitigation'];
            }
        }

        // Join the accumulated lines and add a trailing newline so the block ends cleanly when written raw.
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Derive the escape-hatch config paths a user can set for a rule.
     *
     * @param RuleDefinition $definition - Rule whose option keys, severity threshold, and id seed the config paths.
     *
     * @return list<array{path: string, description: string}> - settable `.gruff-php.yaml` config paths with help text, per-option paths first then
     *                          the always-present enable/score/threshold hatches; empty only when the rule has no options and no threshold
     */
    private function escapeHatchesFor(RuleDefinition $definition): array
    {
        $hatches = [];

        foreach (array_keys($definition->defaultOptions) as $optionKey) {
            $description = $definition->optionDescriptions[$optionKey] ?? 'See `Default options` above.';
            $hatches[]   = [
                'path'        => sprintf('rules.%s.options.%s', $definition->id, $optionKey),
                'description' => $description,
            ];
        }

        $hatches[] = [
            'path'        => sprintf('rules.%s.enabled', $definition->id),
            'description' => 'Set to false to disable the rule entirely.',
        ];
        $hatches[] = [
            'path'        => sprintf('rules.%s.excludeFromScore', $definition->id),
            'description' => 'Set to true to keep findings visible without penalising the composite score (ADR-016).',
        ];

        if ($definition->severityThreshold !== null || $definition->defaultThresholds !== []) {
            $hatches[] = [
                'path'        => sprintf('rules.%s.threshold + severity', $definition->id),
                'description' => 'Override the threshold/severity pair for tunable rules (ADR-008).',
            ];
        }

        // Order matters: per-option paths first, then the always-present enable/score/threshold hatches listed last.
        return $hatches;
    }

    /**
     * Format an option default value for inline display in the detail view.
     *
     * @param int|float|bool|string|array<array-key, int|float|bool|string> $value - Raw default option value from a rule definition.
     *
     * @return string - single-line config-style rendering: booleans as true/false, strings quoted, lists bracketed, numbers bare
     */
    private function formatOptionValue(int|float|bool|string|array $value): string
    {
        if (is_bool($value)) {
            // Render booleans as YAML keywords, not PHP's `1`/empty-string cast, so the value mirrors config syntax.
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            // List options render as a bracketed, comma-joined line; string items stay quoted to read like the source.
            return $value === [] ? '[]' : sprintf('[%s]', implode(', ', array_map(
                static fn($optionValue): string => is_string($optionValue) ? '"' . $optionValue . '"' : (string)$optionValue,
                $value,
            )));
        }

        if (is_string($value)) {
            // Quote string scalars so the reader can tell an empty string from an unset value.
            return '"' . $value . '"';
        }

        // Remaining int/float scalars print bare; their textual form is already unambiguous.
        return (string)$value;
    }

    /**
     * Build the machine-readable row emitted by list-rules.
     *
     * @param RuleDefinition $definition - Rule whose metadata, thresholds, and options populate the catalogue row.
     * @param bool           $enabled - Effective project enabled state; emitted as the `defaultEnabled` field.
     *
     * @return array{id: string, name: string, pillar: string, tier: string, defaultSeverity: string, confidence: string, defaultEnabled: bool,
     *                   thresholds: array<string, int|float|string>|\stdClass, options: array<string, int|float|bool|string|array<array-key,
     *                   int|float|bool|string>>|\stdClass, description: string} - one catalogue row of rule metadata for table or JSON output; empty
     *                   option/threshold maps are stdClass so they encode as `{}` rather than `[]`
     */
    private function ruleMetadataRow(RuleDefinition $definition, bool $enabled): array
    {
        $single     = $definition->severityThreshold;
        $thresholds = $single instanceof \GruffPhp\Engine\Config\SeverityThreshold
            ? ['threshold' => $single->threshold, 'severity' => $single->severity->value]
            : ($definition->defaultThresholds === [] ? (object)[] : $definition->defaultThresholds);

        // Empty maps become stdClass above so JSON encodes them as `{}` not `[]`, keeping the row schema stable.
        return [
            'id'              => $definition->id,
            'name'            => $definition->name,
            'pillar'          => $definition->pillar->value,
            'tier'            => $definition->tier->value,
            'defaultSeverity' => $definition->defaultSeverity->value,
            'confidence'      => $definition->confidence->value,
            'defaultEnabled'  => $enabled,
            'thresholds'      => $thresholds,
            'options'         => $definition->defaultOptions === [] ? (object)[] : $definition->defaultOptions,
            'description'     => $definition->description(),
        ];
    }
}
