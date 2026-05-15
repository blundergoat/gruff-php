<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleRegistry;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints configured rule metadata for humans or tooling.
 */
final class ListRulesCommand extends Command
{
    /**
     * Register list-rules CLI options and metadata.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('list-rules')
            ->setDescription('List gruff-php rule metadata.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json.', default: 'table');
    }

    /**
     * Render rule metadata as either a table or JSON document.
     *
     * @return int Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!is_string($format) || !in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>USAGE-ERROR Unsupported rule-list format. Use table or json.</error>');

            return Command::INVALID;
        }

        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        /** @var list<array{id: string, name: string, pillar: string, tier: string, defaultSeverity: string, confidence: string, defaultEnabled: bool, thresholds: array<string, int|float>|\stdClass, options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass, description: string}> $rows Accumulator shape is built from rule definitions for table rendering. */
        $rows = [];

        foreach ($registry->all() as $rule) {
            $definition = $rule->definition();
            $settings   = $config->ruleSettings($definition->id);
            $rows[]     = $this->row($definition, $settings->enabled);
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

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%s | %s | %s | %s | %s | %s | %s',
                $row['id'],
                $row['pillar'],
                $row['tier'],
                $row['defaultSeverity'],
                $row['confidence'],
                $row['defaultEnabled'] ? 'yes' : 'no',
                $row['description'],
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{id: string, name: string, pillar: string, tier: string, defaultSeverity: string, confidence: string, defaultEnabled: bool, thresholds: array<string, int|float>|\stdClass, options: array<string, int|float|bool|string|array<array-key, int|float|bool|string>>|\stdClass, description: string}
     */
    private function row(RuleDefinition $definition, bool $enabled): array
    {
        return [
            'id' => $definition->id,
            'name' => $definition->name,
            'pillar' => $definition->pillar->value,
            'tier' => $definition->tier->value,
            'defaultSeverity' => $definition->defaultSeverity->value,
            'confidence' => $definition->confidence->value,
            'defaultEnabled' => $enabled,
            'thresholds' => $definition->defaultThresholds === [] ? (object) [] : $definition->defaultThresholds,
            'options' => $definition->defaultOptions === [] ? (object) [] : $definition->defaultOptions,
            'description' => $definition->description(),
        ];
    }
}
