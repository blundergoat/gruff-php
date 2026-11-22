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
 * Lists registered quality rules and shows detailed metadata for a requested rule id.
 * Catalogue and detail output support JSON plus human-readable text; text and table share their respective renderers.
 *
 * Details include defaults, configuration guidance, and documented false positives; unknown ids may receive close-match suggestions.
 */
final class ListRulesCommand extends Command
{
    /**
     * How far a typed rule id may stray from a real one and still earn a "did you mean" suggestion;
     * ids further than this edit distance are treated as unrelated and dropped from the hints.
     */
    private const SUGGESTION_DISTANCE = 4;

    /**
     * Maps single-threshold rule ids to the knob names published in the family listing format.
     * Rules absent from this map use `threshold`, avoiding a second name for an unnamed knob.
     */
    private const THRESHOLD_KNOB_NAMES = [
        'complexity.cognitive'    => 'maxComplexity',
        'complexity.cyclomatic'   => 'maxComplexity',
        'complexity.nesting-depth' => 'maxDepth',
        'size.file-length'        => 'maxLines',
        'size.method-length'      => 'maxLines',
        'size.parameter-count'    => 'maxParameters',
    ];

    /**
     * Declares the list-rules command's rule selector and output format for catalogue or detail views.
     *
     * @return void - Registers the command's metadata with Symfony; nothing is returned.
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
     * Runs the whole command when a user types `gruff-php list-rules`: validate `--format`, then
     * either render one rule's detail view (a `<ruleId>` was given) or the full catalogue.
     *
     * @param InputInterface  $input - Parsed invocation; supplies the `--format` option and optional `<ruleId>` argument.
     * @param OutputInterface $output - Destination the rendered catalogue, detail view, or error is written to.
     *
     * @return int - SUCCESS after output, INVALID for a bad format or unknown rule id, FAILURE if JSON encoding fails.
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

        /** @var list<array{id: string, name: string, pillar: string, tier: string, defaultSeverity: string, confidence: string, defaultEnabled: bool, thresholds?: array<string, int|float>, options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass, description: string}> $rows Accumulator shape is built from rule definitions for table rendering; a row may also carry falsePositiveShapes. */
        $rows = [];

        // Catalogue enablement comes from registry defaults; this command does not load project configuration.

        foreach ($registry->all() as $rule) {
            $definition = $rule->definition();
            $settings   = $config->ruleSettings($definition->id);
            $rows[]     = $this->ruleMetadataRow($definition, $settings->enabled);
        }

        // The full rules list serialises to JSON for tooling to consume; the table and text formats fall through to the human renderer below.
        if ($format === 'json') {
            try {
                $output->write(json_encode(['rules' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, false, OutputInterface::OUTPUT_RAW);
            } catch (JsonException $exception) {
                // json_encode only trips here on unencodable rule metadata; tell the user rather than emit half a document.
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
     * Renders one rule's full detail view once the user passed the ruleId argument, or hands off to the
     * typo path when that id matches nothing in the registry.
     *
     * @param string $ruleId - Requested rule id; matched exactly after resolving a registered retired alias.
     * @param RuleRegistry $registry - Source of the canonical rule set and typo suggestions.
     * @param AnalysisConfig $config - Registry-default configuration supplying the matched rule's enabled state.
     * @param string $format - Pre-validated output format (text, table, or json).
     * @param OutputInterface $output - Destination for the detail view or unknown-rule message.
     *
     * @return int - SUCCESS after output, FAILURE if JSON encoding fails, or INVALID for an unknown id.
     */
    private function renderRuleDetail(string $ruleId, RuleRegistry $registry, AnalysisConfig $config, string $format, OutputInterface $output): int
    {
        $match           = null;
        $requestedRuleId = RuleRegistry::canonicalRuleId($ruleId);
        // Retired ids resolve before matching so callers receive the current rule's detail view.
        foreach ($registry->all() as $rule) {
            $definition = $rule->definition();

            if ($definition->id === $requestedRuleId) {
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
                // json_encode only trips here on an unencodable detail payload; surface the reason instead of a partial document.
                $output->writeln(sprintf('<error>Unable to encode rule detail: %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $output->write($this->renderDetailText($match, $enabled), false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    /**
     * Tells the user that the rule id matched nothing and offers nearby registered ids as typo suggestions.
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
            // Beyond this edit distance the id is too different to be a likely typo, so drop it.
            if ($distance > self::SUGGESTION_DISTANCE) {
                continue;
            }
            $suggestions[] = $candidateId;
            // Keep the suggestion list short so likely corrections do not bury the error.
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
     * Assembles metadata for `list-rules <ruleId> --format json`, including thresholds and configuration guidance.
     *
     * @param RuleDefinition $definition - Rule whose metadata, thresholds, options, and escape hatches are serialised.
     * @param bool $enabled - Registry-default enabled state, emitted as `defaultEnabled`.
     *
     * @return array - JSON detail; empty option maps encode as objects and threshold-free rules omit `thresholds`.
     * @phpstan-return array{
     *     id: string,
     *     name: string,
     *     pillar: string,
     *     tier: string,
     *     defaultSeverity: string,
     *     confidence: string,
     *     defaultEnabled: bool,
     *     description: string,
     *     thresholds?: array<string, int|float>,
     *     options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass,
     *     optionDescriptions: array<string, string>|\stdClass,
     *     escapeHatches: list<array{path: string, description: string}>,
     *     falsePositiveShapes: list<array{shape: string, mitigation: string}>
     * }
     */
    private function ruleDetailPayload(RuleDefinition $definition, bool $enabled): array
    {
        $payload = [
            'id'                  => $definition->id,
            'name'                => $definition->name,
            'pillar'              => $definition->pillar->value,
            'tier'                => $definition->tier->value,
            'defaultSeverity'     => $definition->defaultSeverity->value,
            'confidence'          => $definition->confidence->value,
            'defaultEnabled'      => $enabled,
            'description'         => $definition->description(),
            'options'             => $definition->defaultOptions === [] ? (object)[] : $definition->defaultOptions,
            'optionDescriptions'  => $definition->optionDescriptions === [] ? (object)[] : $definition->optionDescriptions,
            'escapeHatches'       => $this->escapeHatchesFor($definition),
            'falsePositiveShapes' => $definition->falsePositiveShapes,
        ];

        // A rule with no tunable threshold omits the key; an absent key is the family's "none".
        $thresholds = $this->thresholdsPayload($definition);
        if ($thresholds !== null) {
            $payload['thresholds'] = $thresholds;
        }

        return $payload;
    }

    /**
     * Lays out one rule's detail as the block a person reads in the terminal - identity header,
     * description, default options, .gruff-php.yaml escape hatches, and false-positive shapes,
     * with default options and false-positive shapes shown only when the rule has them.
     *
     * @param RuleDefinition $definition - Rule whose name, pillar, options, hatches, and false-positive shapes render.
     * @param bool $enabled - Registry-default enabled state; printed on the "Enabled by default" line.
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
        // Every rule has enable and score-exclusion entries, so configuration guidance is always available.
        if ($hatches !== []) {
            $lines[]   = '';
            $lines[]   = 'Escape hatches:';
            $pathWidth = max(array_map(static fn(array $escapeHatch): int => strlen($escapeHatch['path']), $hatches));
            // Align each control label with its description so users can scan the available configuration changes.
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


        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Lists the .gruff-php.yaml controls a user can set to retune or silence a rule - the
     * per-option keys first, then the always-present enable and exclude-from-score switches, and a
     * threshold/severity pair for the rules that support one.
     *
     * @param RuleDefinition $definition - Rule whose option keys, severity threshold, and id seed the displayed controls.
     *
     * @return list<array{path: string, description: string}> - Ordered control labels; always includes enable and score controls.
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

        // Keep option-specific controls first so the detail view presents them before shared enable and score controls.
        return $hatches;
    }

    /**
     * Renders one option's default value the way it would look in `.gruff-php.yaml`, so the detail
     * view shows a value the user could copy straight into their config.
     *
     * @param int|float|bool|string|array<array-key, int|float|bool|string> $value - Raw default option value from a rule definition.
     *
     * @return string - single-line config-style rendering: booleans as true/false, strings quoted, lists bracketed, numbers bare
     */
    private function formatOptionValue(int|float|bool|string|array $value): string
    {
        // Render booleans as the YAML keywords `true`/`false`, not PHP's `1`/empty-string cast, so the value mirrors config syntax.
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // List options render as a bracketed, comma-joined line; string items stay quoted to read like the source.
        if (is_array($value)) {
            return $value === [] ? '[]' : sprintf('[%s]', implode(', ', array_map(
                static fn($optionValue): string => is_string($optionValue) ? '"' . $optionValue . '"' : (string)$optionValue,
                $value,
            )));
        }

        // Quote string scalars so the reader can tell an empty string from an unset value.
        if (is_string($value)) {
            return '"' . $value . '"';
        }

        // Remaining int/float scalars print bare; their textual form is already unambiguous.
        return (string)$value;
    }

    /**
     * Builds the metadata row used by the catalogue's table and JSON renderers.
     * JSON retains additional fields that the table does not display.
     *
     * @param RuleDefinition $definition - Rule whose metadata, thresholds, and options populate the catalogue row.
     * @param bool $enabled - Registry-default enabled state, emitted as `defaultEnabled`.
     *
     * @return array - Catalogue metadata with optional thresholds and non-empty guidance; empty options encode as an object.
     *
     * @phpstan-return array{
     *     id: string,
     *     name: string,
     *     pillar: string,
     *     tier: string,
     *     defaultSeverity: string,
     *     confidence: string,
     *     defaultEnabled: bool,
     *     thresholds?: array<string, int|float>,
     *     options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass,
     *     description: string,
     *     falsePositiveShapes?: list<array{shape: string, mitigation: string}>
     * }
     */
    private function ruleMetadataRow(RuleDefinition $definition, bool $enabled): array
    {
        $ruleMetadata = [
            'id'              => $definition->id,
            'name'            => $definition->name,
            'pillar'          => $definition->pillar->value,
            'tier'            => $definition->tier->value,
            'defaultSeverity' => $definition->defaultSeverity->value,
            'confidence'      => $definition->confidence->value,
            'defaultEnabled'  => $enabled,
            'options'         => $definition->defaultOptions === [] ? (object)[] : $definition->defaultOptions,
            'description'     => $definition->description(),
        ];

        // A rule with no tunable threshold omits the key; an absent key is the family's "none".
        $thresholds = $this->thresholdsPayload($definition);
        if ($thresholds !== null) {
            $ruleMetadata['thresholds'] = $thresholds;
        }



        if ($definition->falsePositiveShapes !== []) {
            $ruleMetadata['falsePositiveShapes'] = $definition->falsePositiveShapes;
        }

        return $ruleMetadata;
    }

    /**
     * Projects default thresholds into the family listing's named numeric map.
     * Single thresholds use the mapped knob or `threshold`; named maps are unchanged and severities are omitted.
     *
     * @param RuleDefinition $definition - Rule whose severity threshold or named defaults are projected.
     *
     * @return array<string, int|float>|null - Knob-name-to-number map, or null when the rule has no threshold.
     */
    private function thresholdsPayload(RuleDefinition $definition): ?array
    {
        $single = $definition->severityThreshold;
        if ($single instanceof \GruffPhp\Engine\Config\SeverityThreshold) {
            $knob = self::THRESHOLD_KNOB_NAMES[$definition->id] ?? 'threshold';

            return [$knob => $single->threshold];
        }

        return $definition->defaultThresholds === [] ? null : $definition->defaultThresholds;
    }
}
