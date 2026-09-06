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
 * Backs the `gruff-php list-rules` command - the browsable catalogue of every quality rule the
 * analyser can apply.
 *
 * Reach for this when a user asks "what does gruff check?" or wants to inspect one rule before
 * tuning it. With no argument it prints the whole catalogue as a table, JSON, or plain text; pass
 * a `<ruleId>` and it renders that rule in full - severity, options, the `.gruff-php.yaml` escape
 * hatches that disable or retune it, and known false-positive shapes. A mistyped id gets a
 * "did you mean" nudge instead of an empty result.
 */
final class ListRulesCommand extends Command
{
    /**
     * How far a typed rule id may stray from a real one and still earn a "did you mean" suggestion;
     * ids further than this edit distance are treated as unrelated and dropped from the hints.
     */
    private const SUGGESTION_DISTANCE = 4;

    /**
     * Declares the `list-rules` command name, its optional `<ruleId>` argument, and the `--format`
     * flag - everything the user can type after `gruff-php list-rules`.
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
     * @return int - Symfony exit code: SUCCESS once output is written, INVALID for a bad format, FAILURE if JSON encoding fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        // Reject anything but the three renderers we support, so a bad flag like `--format=xml` fails
        // fast with a usage error rather than a broken render at the end.
        if (!is_string($format) || !in_array($format, ['text', 'table', 'json'], true)) {
            $output->writeln('<error>USAGE-ERROR Unsupported rule-list format. Use text, table, or json.</error>');

            return Command::INVALID;
        }

        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $ruleId   = $input->getArgument('ruleId');

        // A non-empty `<ruleId>` means the user wants one rule in depth, so branch to the detail view.
        if (is_string($ruleId) && $ruleId !== '') {
            return $this->renderRuleDetail(
                ruleId:   $ruleId,
                registry: $registry,
                config:   $config,
                format:   $format,
                output:   $output,
            );
        }

        /** @var list<array{id: string, name: string, pillar: string, tier: string, defaultSeverity: string, confidence: string, defaultEnabled: bool, thresholds: array<string, int|float>|\stdClass, options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass, description: string}> $rows Accumulator shape is built from rule definitions for table rendering; a row may also carry falsePositiveShapes. */
        $rows = [];

        // No id given: build one catalogue row per registered rule, tagging each with whether this
        // project currently has it enabled.
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

        // Emit one Markdown-style table row per rule, in registry order, under the header just printed.
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
     * Renders one rule's full detail view once the user passed a `<ruleId>`, or hands off to the
     * typo path when that id matches nothing in the registry.
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
        // Scan the registry for the exact id the user typed; rule ids are matched literally, not fuzzily.
        foreach ($registry->all() as $rule) {
            $definition = $rule->definition();
            // Stop at the first exact hit - that is the rule whose detail view we will render.
            if ($definition->id === $ruleId) {
                $match = $definition;
                break;
            }
        }

        // Nothing matched, so the id was mistyped or retired; hand off to the "did you mean" response.
        if ($match === null) {
            return $this->renderRuleNotFound($ruleId, $registry, $output);
        }

        $enabled = $config->ruleSettings($match->id)->enabled;

        // A single rule's detail serialises to JSON for tooling; text and table share the human renderer below.
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
     * Tells the user their `<ruleId>` matched no rule and offers up to three closest ids as
     * "did you mean" hints, so a small typo like `naming-cammel-case` still points somewhere useful.
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
        // Score every known id by how many edits separate it from what the user typed.
        foreach ($registry->all() as $rule) {
            $candidateId              = $rule->definition()->id;
            $candidates[$candidateId] = levenshtein($ruleId, $candidateId);
        }

        asort($candidates);
        $suggestions = [];
        // Walk the ids closest-first (asort ordered them by distance) to pick the best few hints.
        foreach ($candidates as $candidateId => $distance) {
            // Beyond this edit distance the id is too different to be a likely typo, so drop it.
            if ($distance > self::SUGGESTION_DISTANCE) {
                continue;
            }
            $suggestions[] = $candidateId;
            // Three hints is enough to be helpful without burying the user in near-misses.
            if (count($suggestions) === 3) {
                break;
            }
        }

        // Only add the "did you mean" line when at least one id was close enough to suggest.
        if ($suggestions !== []) {
            $output->writeln(sprintf('Did you mean: %s ?', implode(', ', $suggestions)));
        }

        return Command::INVALID;
    }

    /**
     * Assembles one rule's detail as a JSON-ready associative array for `list-rules <ruleId>
     * --format json`, so tooling reads the same facts the text view shows a person.
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

        // An empty thresholds map becomes stdClass so this detail JSON shows `thresholds: {}` rather than an array.
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
     * Lays out one rule's detail as the block a person reads in the terminal - identity header,
     * description, default options, `.gruff-php.yaml` escape hatches, and false-positive shapes,
     * with default options and false-positive shapes shown only when the rule has them.
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

        // Show the tunable options only for rules that have them; a rule with no knobs skips this block.
        if ($definition->defaultOptions !== []) {
            $lines[]  = '';
            $lines[]  = 'Default options:';
            $keyWidth = max(array_map('strlen', array_keys($definition->defaultOptions)));
            // One aligned row per option: its key, the default value, and any help text the rule supplies.
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
        // List the `.gruff-php.yaml` paths that switch this rule off or retune it; every rule carries at least the enable/exclude-from-score hatches, so this block always renders.
        if ($hatches !== []) {
            $lines[]   = '';
            $lines[]   = 'Escape hatches:';
            $pathWidth = max(array_map(static fn(array $escapeHatch): int => strlen($escapeHatch['path']), $hatches));
            // One aligned row per config path, so a user can copy the exact key to change in their config.
            foreach ($hatches as $hatch) {
                $lines[] = sprintf(
                    '  %-' . $pathWidth . 's  %s',
                    $hatch['path'],
                    $hatch['description'],
                );
            }
        }

        // Only rules with documented false positives get this section; it warns where the check can misfire.
        if ($definition->falsePositiveShapes !== []) {
            $lines[] = '';
            $lines[] = 'Common false-positive shapes:';
            // Pair each misfire shape with its mitigation so the user knows how to quiet a false alarm.
            foreach ($definition->falsePositiveShapes as $entry) {
                $lines[] = '  - ' . $entry['shape'];
                $lines[] = '    Mitigation: ' . $entry['mitigation'];
            }
        }

        // Join the accumulated lines and add a trailing newline so the block ends cleanly when written raw.
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Lists the `.gruff-php.yaml` config paths a user can set to retune or silence a rule - the
     * per-option keys first, then the always-present enable and exclude-from-score switches, and a
     * threshold/severity pair for the rules that support one.
     *
     * @param RuleDefinition $definition - Rule whose option keys, severity threshold, and id seed the config paths.
     *
     * @return list<array{path: string, description: string}> - settable `.gruff-php.yaml` config paths with help text: per-option paths first,
     *                          then the enable and exclude-from-score switches every rule gets, and a threshold/severity path for tunable rules. Never empty.
     */
    private function escapeHatchesFor(RuleDefinition $definition): array
    {
        $hatches = [];

        // One hatch per tunable option, pointing at the exact `rules.<id>.options.<key>` path to set.
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

        // Add the threshold/severity hatch only for rules that actually have a tunable threshold.
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
     * Flattens one rule into the single row the catalogue shows - the shared shape behind both the
     * Markdown table and the `--format json` list, so a rule reads the same either way.
     *
     * @param RuleDefinition $definition - Rule whose metadata, thresholds, and options populate the catalogue row.
     * @param bool           $enabled - Effective project enabled state; emitted as the `defaultEnabled` field.
     *
     * @return array - one catalogue row of rule metadata for table or JSON output; empty option/threshold
     *                   maps are stdClass so they encode as `{}` rather than `[]`, and `falsePositiveShapes`
     *                   is present only for rules that catalogue guidance
     *
     * @phpstan-return array{
     *     id: string,
     *     name: string,
     *     pillar: string,
     *     tier: string,
     *     defaultSeverity: string,
     *     confidence: string,
     *     defaultEnabled: bool,
     *     thresholds: array<string, int|float|string>|\stdClass,
     *     options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass,
     *     description: string,
     *     falsePositiveShapes?: list<array{shape: string, mitigation: string}>
     * }
     */
    private function ruleMetadataRow(RuleDefinition $definition, bool $enabled): array
    {
        $single     = $definition->severityThreshold;
        $thresholds = $single instanceof \GruffPhp\Engine\Config\SeverityThreshold
            ? ['threshold' => $single->threshold, 'severity' => $single->severity->value]
            : ($definition->defaultThresholds === [] ? (object)[] : $definition->defaultThresholds);

        // Coerce an empty thresholds map to stdClass so this row's `thresholds` stays object-typed across every rule listed.
        $ruleMetadata = [
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

        // Catalogued guidance rides along only where a rule has some; an absent key means none is
        // catalogued, which reads differently from a rule that documents an empty list.
        if ($definition->falsePositiveShapes !== []) {
            $ruleMetadata['falsePositiveShapes'] = $definition->falsePositiveShapes;
        }

        return $ruleMetadata;
    }
}
