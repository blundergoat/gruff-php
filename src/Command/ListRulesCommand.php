<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleRegistry;
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
     * @return int Symfony command exit code.
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
            return $this->renderRuleDetail($ruleId, $registry, $config, $format, $output);
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
     * @return int Symfony command exit code.
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
     * @return int Symfony command exit code.
     */
    private function renderRuleNotFound(string $ruleId, RuleRegistry $registry, OutputInterface $output): int
    {
        $output->writeln(sprintf('<error>Unknown rule: %s.</error>', $ruleId));

        $candidates = [];
        foreach ($registry->all() as $rule) {
            $candidateId = $rule->definition()->id;
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
     * @return array{id: string, name: string, pillar: string, tier: string, defaultSeverity: string, confidence: string, defaultEnabled: bool, description: string, thresholds: array<string, int|float|string>|\stdClass, options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass, optionDescriptions: array<string, string>|\stdClass, escapeHatches: list<array{path: string, description: string}>, falsePositiveShapes: list<array{shape: string, mitigation: string}>}
     */
    private function ruleDetailPayload(RuleDefinition $definition, bool $enabled): array
    {
        $single     = $definition->severityThreshold;
        $thresholds = $single instanceof \GruffPhp\Config\SeverityThreshold
            ? ['threshold' => $single->threshold, 'severity' => $single->severity->value]
            : ($definition->defaultThresholds === [] ? (object) [] : $definition->defaultThresholds);

        return [
            'id' => $definition->id,
            'name' => $definition->name,
            'pillar' => $definition->pillar->value,
            'tier' => $definition->tier->value,
            'defaultSeverity' => $definition->defaultSeverity->value,
            'confidence' => $definition->confidence->value,
            'defaultEnabled' => $enabled,
            'description' => $definition->description(),
            'thresholds' => $thresholds,
            'options' => $definition->defaultOptions === [] ? (object) [] : $definition->defaultOptions,
            'optionDescriptions' => $definition->optionDescriptions === [] ? (object) [] : $definition->optionDescriptions,
            'escapeHatches' => $this->escapeHatchesFor($definition),
            'falsePositiveShapes' => $definition->falsePositiveShapes,
        ];
    }

    /**
     * Render a per-rule detail view as multi-line text.
     *
     * @return string Detail text with a trailing newline.
     */
    private function renderDetailText(RuleDefinition $definition, bool $enabled): string
    {
        $lines = [];
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
            $lines[] = '';
            $lines[] = 'Default options:';
            $keyWidth = max(array_map('strlen', array_keys($definition->defaultOptions)));
            foreach ($definition->defaultOptions as $key => $defaultValue) {
                $valueRender  = $this->formatOptionValue($defaultValue);
                $description  = $definition->optionDescriptions[$key] ?? '';
                $lines[] = sprintf(
                    '  %-' . $keyWidth . 's  %s%s',
                    $key,
                    $valueRender,
                    $description === '' ? '' : '  ' . $description,
                );
            }
        }

        $hatches = $this->escapeHatchesFor($definition);
        if ($hatches !== []) {
            $lines[] = '';
            $lines[] = 'Escape hatches:';
            $pathWidth = max(array_map(static fn (array $row): int => strlen($row['path']), $hatches));
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
     * Derive the escape-hatch config paths a user can set for a rule.
     *
     * @return list<array{path: string, description: string}>
     */
    private function escapeHatchesFor(RuleDefinition $definition): array
    {
        $hatches = [];

        foreach (array_keys($definition->defaultOptions) as $optionKey) {
            $description = $definition->optionDescriptions[$optionKey] ?? 'See `Default options` above.';
            $hatches[]   = [
                'path' => sprintf('rules.%s.options.%s', $definition->id, $optionKey),
                'description' => $description,
            ];
        }

        $hatches[] = [
            'path' => sprintf('rules.%s.enabled', $definition->id),
            'description' => 'Set to false to disable the rule entirely.',
        ];
        $hatches[] = [
            'path' => sprintf('rules.%s.excludeFromScore', $definition->id),
            'description' => 'Set to true to keep findings visible without penalising the composite score (ADR-016).',
        ];

        if ($definition->severityThreshold !== null || $definition->defaultThresholds !== []) {
            $hatches[] = [
                'path' => sprintf('rules.%s.threshold + severity', $definition->id),
                'description' => 'Override the threshold/severity pair for tunable rules (ADR-008).',
            ];
        }

        return $hatches;
    }

    /**
     * Format an option default value for inline display in the detail view.
     *
     * @param int|float|bool|string|array<array-key, int|float|bool|string> $value
     * @return string Compact, single-line representation.
     */
    private function formatOptionValue(int|float|bool|string|array $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return $value === [] ? '[]' : sprintf('[%s]', implode(', ', array_map(
                static fn ($item): string => is_string($item) ? '"' . $item . '"' : (string) $item,
                $value,
            )));
        }

        if (is_string($value)) {
            return '"' . $value . '"';
        }

        return (string) $value;
    }

    /**
     * Build the machine-readable row emitted by list-rules.
     *
     * @return array{id: string, name: string, pillar: string, tier: string, defaultSeverity: string, confidence: string, defaultEnabled: bool, thresholds: array<string, int|float|string>|\stdClass, options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass, description: string}
     */
    private function ruleMetadataRow(RuleDefinition $definition, bool $enabled): array
    {
        $single     = $definition->severityThreshold;
        $thresholds = $single instanceof \GruffPhp\Config\SeverityThreshold
            ? ['threshold' => $single->threshold, 'severity' => $single->severity->value]
            : ($definition->defaultThresholds === [] ? (object) [] : $definition->defaultThresholds);

        return [
            'id' => $definition->id,
            'name' => $definition->name,
            'pillar' => $definition->pillar->value,
            'tier' => $definition->tier->value,
            'defaultSeverity' => $definition->defaultSeverity->value,
            'confidence' => $definition->confidence->value,
            'defaultEnabled' => $enabled,
            'thresholds' => $thresholds,
            'options' => $definition->defaultOptions === [] ? (object) [] : $definition->defaultOptions,
            'description' => $definition->description(),
        ];
    }
}
