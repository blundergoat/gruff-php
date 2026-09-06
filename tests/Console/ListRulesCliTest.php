<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Cli\Application;
use GruffPhp\Rules\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rules\Naming\GenericMethodNameRule;
use GruffPhp\Rules\Naming\BooleanPrefixRule;
use GruffPhp\Rules\Security\DangerousFunctionCallRule;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Covers the version, list, and help CLI commands running end-to-end through the built binary on a clean checkout.
 */
final class ListRulesCliTest extends CliTestCase
{
    /**
     * Verify version command runs through binary.
     *
     * @return void
     */
    public function testVersionCommandRunsThroughBinary(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', '--version']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff-php', $process->getOutput());
        self::assertStringContainsString(Application::VERSION, $process->getOutput());
    }

    /**
     * Verify list command runs through binary.
     *
     * @return void
     */
    public function testListCommandRunsThroughBinary(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('analyse', $process->getOutput());
        self::assertStringContainsString('dashboard', $process->getOutput());
        self::assertStringContainsString('report', $process->getOutput());
    }

    /**
     * Verify list-rules accepts the shared text format alias.
     *
     * @return void
     */
    public function testListRulesAcceptsTextFormatAlias(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list-rules', '--format', 'text']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Rule ID | Pillar | Tier | Severity | Confidence | Enabled | Description', $process->getOutput());
    }

    /**
     * Verify list-rules with a rule id argument renders the per-rule detail view.
     *
     * @return void
     */
    public function testListRulesRendersPerRuleDetailViewForKnownId(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'naming.identifier-quality',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $output = $process->getOutput();
        self::assertStringContainsString('Rule: naming.identifier-quality', $output);
        self::assertStringContainsString('Default options:', $output);
        self::assertStringContainsString('placeholderNames', $output);
        self::assertStringContainsString('Escape hatches:', $output);
        self::assertStringContainsString('rules.naming.identifier-quality.excludeFromScore', $output);
        self::assertStringContainsString('Common false-positive shapes:', $output);
    }

    /**
     * Verify list-rules detail view JSON includes the structured payload.
     *
     * @return void
     */
    public function testListRulesDetailJsonIncludesStructuredFields(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'waste.one-line-method',
            '--format',
            'json',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame('waste.one-line-method', $payload['id'] ?? null);
        self::assertArrayHasKey('escapeHatches', $payload);
        self::assertArrayHasKey('falsePositiveShapes', $payload);
        self::assertIsArray($payload['escapeHatches']);
        self::assertNotEmpty($payload['escapeHatches']);
    }

    /**
     * Verify the catalogue publishes false-positive guidance for every medium and low confidence rule.
     *
     * One method covers the whole guarantee - population, per-rule guidance, and the deliberate
     * absence of the key - because each assertion reads the same catalogue payload.
     *
     * @return void
     */
    public function testCatalogueJsonPublishesGuidanceForEveryMediumAndLowConfidenceRule(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list-rules', '--format', 'json']);
        $process->run();
        $exitCode = $process->getExitCode();
        $payload  = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        /** @var list<array{id: string, confidence: string, falsePositiveShapes?: list<array{shape: string, mitigation: string}>}> $rules Catalogue rows as published by the JSON renderer. */
        $rules = (array)(is_array($payload) ? ($payload['rules'] ?? []) : []);

        $mediumOrLowRules = array_values(array_filter(
            $rules,
            static fn(array $rule): bool => in_array($rule['confidence'], ['medium', 'low'], true),
        ));
        $withoutGuidance = array_values(array_map(
            static fn(array $rule): string => $rule['id'],
            array_filter(
                $mediumOrLowRules,
                static fn(array $rule): bool => ($rule['falsePositiveShapes'] ?? []) === [],
            ),
        ));
        // Name every rule with a blank half so one failure lists them all rather than only the first.
        $blankGuidance = array_values(array_map(
            static fn(array $rule): string => $rule['id'],
            array_filter($mediumOrLowRules, static fn(array $rule): bool => array_filter(
                $rule['falsePositiveShapes'] ?? [],
                static fn(array $entry): bool => trim($entry['shape']) === '' || trim($entry['mitigation']) === '',
            ) !== []),
        ));

        // A rule that catalogues nothing omits the key rather than publishing an empty list, so an
        // absent key never reads as "reviewed and found to have no false positives".
        $shapelessRules       = array_values(array_filter(
            $rules,
            static fn(array $rule): bool => !array_key_exists('falsePositiveShapes', $rule),
        ));
        $shapelessConfidences = array_values(array_unique(array_map(
            static fn(array $rule): string => (string)$rule['confidence'],
            $shapelessRules,
        )));

        self::assertSame(0, $exitCode, $process->getErrorOutput());
        self::assertCount(128, $rules);
        self::assertCount(70, $mediumOrLowRules);
        self::assertSame([], $withoutGuidance);
        self::assertSame([], $blankGuidance);
        self::assertNotEmpty($shapelessRules);
        self::assertSame(['high'], $shapelessConfidences);
    }

    /**
     * Verify guidance catalogued before this batch is still published by both catalogue surfaces.
     *
     * @return void
     */
    public function testPreExistingGuidanceStaysPublishedInCatalogueAndDetailViews(): void
    {
        $listProcess = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list-rules', '--format', 'json']);
        $listProcess->run();
        $detailProcess = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'waste.one-line-method',
            '--format',
            'json',
        ]);
        $detailProcess->run();

        $listPayload   = json_decode($listProcess->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        $detailPayload = json_decode($detailProcess->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        /** @var list<array{id: string, falsePositiveShapes?: list<array{shape: string, mitigation: string}>}> $catalogueRules Catalogue rows as published by the JSON renderer. */
        $catalogueRules  = (array)(is_array($listPayload) ? ($listPayload['rules'] ?? []) : []);
        $catalogueShapes = array_merge(...array_map(
            static fn(array $rule): array => $rule['falsePositiveShapes'] ?? [],
            array_values(array_filter(
                $catalogueRules,
                static fn(array $rule): bool => $rule['id'] === 'waste.one-line-method',
            )),
        ));
        $detailShapes = (array)(is_array($detailPayload) ? ($detailPayload['falsePositiveShapes'] ?? []) : []);

        self::assertSame(0, $listProcess->getExitCode(), $listProcess->getErrorOutput());
        self::assertSame(0, $detailProcess->getExitCode(), $detailProcess->getErrorOutput());
        self::assertNotEmpty($catalogueShapes);
        // The catalogue row and the detail card publish one text, so a reader comparing them agrees.
        self::assertSame($detailShapes, $catalogueShapes);
    }

    /**
     * Verify Boolean detail JSON preserves the documented option semantics added for configuration users.
     *
     * @return void
     */
    public function testBooleanRuleDetailJsonKeepsDocumentedOptionSemantics(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            BooleanPrefixRule::ID,
            '--format',
            'json',
        ]);
        $process->run();

        $payload = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertIsArray($payload);
        self::assertSame(BooleanPrefixRule::ID, $payload['id'] ?? null);
        self::assertSame(
            [
                'allowedPrefixes' => 'Leading predicate words accepted at camelCase or snake_case word boundaries.',
                'stateAdjectiveAllowlist' => 'Exact whole Boolean names accepted for typed properties and parameters only.',
                'stateSuffixAllowlist' => 'Final whole tokens accepted on Boolean names containing at least two tokens across methods, functions, properties, and parameters.',
                'propositionVerbAllowlist' => 'Internal whole verbs accepted only with a subject token before and a context token after.',
                'acceptedBooleanNames' => 'Exact case-insensitive Boolean names accepted across receivers for compatibility.',
                'includePublicApi' => 'Whether to inspect public/protected methods, properties, named functions, and their caller-visible parameters; false limits findings to private/local declarations.',
            ],
            $payload['optionDescriptions'] ?? null,
        );
    }

    /**
     * Verify requested rule detail JSON preserves replacement, toggle, and additive option contracts.
     *
     * @return void
     */
    public function testRequestedRuleDetailsExposeDocumentedOptionContracts(): void
    {
        $expectedByRule = [
            GenericMethodNameRule::ID => [
                'options' => [
                    'genericNames' => [
                        'process',
                        'handle',
                        'execute',
                        'run',
                        'manage',
                        'doIt',
                        'do',
                        'perform',
                        'make',
                        'compute',
                    ],
                ],
                'optionDescriptions' => [
                    'genericNames' => 'Replacement list of function and method names treated as generic; matching is case-insensitive.',
                ],
            ],
            MissingPropertyPhpdocRule::ID => [
                'options' => ['acceptLineComments' => false],
                'optionDescriptions' => [
                    'acceptLineComments' => 'When true, a physically attached // or # comment with meaning beyond the property name satisfies the rule.',
                ],
            ],
            DangerousFunctionCallRule::ID => [
                'options' => ['additionalFunctions' => []],
                'optionDescriptions' => [
                    'additionalFunctions' => 'Global function names added to the non-removable built-in execution list; matching is case-insensitive.',
                ],
            ],
        ];

        foreach ($expectedByRule as $ruleId => $expectedPayload) {
            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'list-rules',
                $ruleId,
                '--format',
                'json',
            ]);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $payload = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

            self::assertIsArray($payload, sprintf('Expected JSON array for %s.', $ruleId));
            self::assertSame(
                $ruleId,
                $payload['id'] ?? null,
                sprintf('Detail payload id mismatch for %s.', $ruleId),
            );
            self::assertSame(
                $expectedPayload['options'],
                $payload['options'] ?? null,
                sprintf('Default options mismatch for %s.', $ruleId),
            );
            self::assertSame(
                $expectedPayload['optionDescriptions'],
                $payload['optionDescriptions'] ?? null,
                sprintf('Option descriptions mismatch for %s.', $ruleId),
            );
        }
    }

    /**
     * Verify list-rules with an unknown rule id suggests near matches and exits INVALID.
     *
     * @return void
     */
    public function testListRulesUnknownRuleSuggestsNearMatches(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'naming.identifier-qualty',
        ]);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        $combined = $process->getOutput() . $process->getErrorOutput();
        self::assertStringContainsString('Unknown rule', $combined);
        self::assertStringContainsString('Did you mean', $combined);
        self::assertStringContainsString('naming.identifier-quality', $combined);
    }

    /**
     * Verify clean checkout install runs CLI help.
     *
     * @return void
     */
    public function testCleanCheckoutInstallRunsCliHelp(): void
    {
        $composerPath = (new ExecutableFinder())->find('composer');

        self::assertIsString($composerPath);

        $tempDir  = $this->tempDir();
        $checkout = $tempDir . '/gruff-php';

        try {
            $this->copyPackageTree(self::PROJECT_ROOT, $checkout);

            $installProcess = new Process([
                $composerPath,
                'install',
                '--no-dev',
                '--no-interaction',
                '--prefer-dist',
                '--no-progress',
            ], $checkout);
            $installProcess->setTimeout(120);
            $installProcess->run();

            self::assertSame(0, $installProcess->getExitCode(), $installProcess->getErrorOutput() . $installProcess->getOutput());

            $helpProcess = new Process([PHP_BINARY, $checkout . '/bin/gruff-php', '--help'], $checkout);
            $helpProcess->run();

            self::assertSame(0, $helpProcess->getExitCode(), $helpProcess->getErrorOutput());
            self::assertStringContainsString('Description:', $helpProcess->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify Composer's vendor/bin proxy boots the package through the consumer project's autoloader.
     *
     * @return void
     * @throws \JsonException
     */
    public function testInstalledVendorBinProxyUsesConsumerAutoloader(): void
    {
        $composerPath = (new ExecutableFinder())->find('composer');

        self::assertIsString($composerPath);

        $tempDir  = $this->tempDir();
        $package  = $tempDir . '/package/gruff-php';
        $consumer = $tempDir . '/consumer';

        try {
            $this->copyPackageTree(self::PROJECT_ROOT, $package);
            self::assertTrue(mkdir($consumer));

            $composerJson = json_encode([
                'repositories' => [[
                    'type' => 'path',
                    'url' => $package,
                    'options' => [
                        'symlink' => false,
                        'versions' => [
                            'blundergoat/gruff-php' => '0.1.x-dev',
                        ],
                    ],
                ]],
                'require-dev' => [
                    'blundergoat/gruff-php' => '0.1.x-dev',
                ],
                'minimum-stability' => 'dev',
                'prefer-stable' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            file_put_contents($consumer . '/composer.json', $composerJson . "\n");

            $installProcess = new Process([
                $composerPath,
                'update',
                '--no-audit',
                '--no-interaction',
                '--prefer-dist',
                '--no-progress',
            ], $consumer);
            $installProcess->setTimeout(120);
            $installProcess->run();

            self::assertSame(0, $installProcess->getExitCode(), $installProcess->getErrorOutput() . $installProcess->getOutput());
            self::assertFileDoesNotExist($consumer . '/vendor/blundergoat/gruff-php/vendor/autoload.php');

            $initProcess = new Process([
                PHP_BINARY,
                $consumer . '/vendor/bin/gruff-php',
                'init',
            ], $consumer);
            $initProcess->run();

            self::assertSame(0, $initProcess->getExitCode(), $initProcess->getErrorOutput() . $initProcess->getOutput());
            self::assertFileExists($consumer . '/.gruff-php.yaml');
            self::assertStringContainsString('Wrote ' . $consumer . '/.gruff-php.yaml', $initProcess->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }
}
