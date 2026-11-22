<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Cli\Application;
use GruffPhp\Rules\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rules\Security\DangerousFunctionCallRule;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Exercises version, command listing and rule-catalogue output through the CLI binary.
 * Checks selected text and JSON fields, guidance, options and threshold maps.
 * Installation fixtures cover help after a production install and init through a consumer's vendor/bin proxy.
 */
final class ListRulesCliTest extends CliTestCase
{
    /**
     * Verify version command runs through binary.
     *
     * @return void - Asserts a successful exit and the product name and version in stdout.
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
     * @return void - Asserts a successful exit and analyse, dashboard and report in the command list.
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
     * @return void - Asserts a successful exit and the expected table header for text output.
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
     * @return void - Asserts the rule identity, option and escape-hatch text, and false-positive guidance heading.
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
     * Verifies that the retired docs.missing-public-phpdoc ID displays the docs.missing-phpdoc detail heading.
     *
     * @return void - Asserts a successful exit and the replacement rule's heading.
     */
    public function testListRulesResolvesARetiredIdToItsReplacement(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'docs.missing-public-phpdoc',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Rule: docs.missing-phpdoc', $process->getOutput());
    }

    /**
     * Verify list-rules detail view JSON includes the structured payload.
     *
     * @return void - Asserts the requested ID, guidance-key presence and nonempty escape hatches.
     */
    public function testListRulesDetailJsonIncludesStructuredFields(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'waste.commented-out-code',
            '--format',
            'json',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame('waste.commented-out-code', $payload['id'] ?? null);
        self::assertArrayHasKey('escapeHatches', $payload);
        self::assertArrayHasKey('falsePositiveShapes', $payload);
        self::assertIsArray($payload['escapeHatches']);
        self::assertNotEmpty($payload['escapeHatches']);
    }

    /**
     * Verify the catalogue publishes false-positive guidance for every medium and low confidence rule.
     * Checks catalogue counts, nonblank guidance entries and high confidence for rows without guidance.
     *
     * @return void - Asserts catalogue counts, complete medium/low guidance and high confidence for rows without guidance.
     */
    public function testCatalogueJsonPublishesGuidanceForEveryMediumAndLowConfidenceRule(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list-rules', '--format', 'json']);
        $process->run();
        $exitCode = $process->getExitCode();
        $payload  = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        /**
         * @var list<array{id: string, confidence: string, falsePositiveShapes?: list<array{shape: string, mitigation: string}>}> $rules
         *     - Catalogue rows as published by the JSON renderer.
         */
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

        self::assertCount(82, $rules);
        self::assertCount(41, $mediumOrLowRules);
        self::assertSame([], $withoutGuidance);
        self::assertSame([], $blankGuidance);
        self::assertNotEmpty($shapelessRules);
        self::assertSame(['high'], $shapelessConfidences);
    }

    /**
     * Verifies that waste.commented-out-code publishes the same nonempty guidance in catalogue and detail JSON.
     *
     * @return void - Asserts successful exits and identical nonempty guidance for the selected rule.
     */
    public function testPreExistingGuidanceStaysPublishedInCatalogueAndDetailViews(): void
    {
        $listProcess = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list-rules', '--format', 'json']);
        $listProcess->run();
        $detailProcess = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'waste.commented-out-code',
            '--format',
            'json',
        ]);
        $detailProcess->run();

        $listPayload   = json_decode($listProcess->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        $detailPayload = json_decode($detailProcess->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        /**
         * @var list<array{id: string, falsePositiveShapes?: list<array{shape: string, mitigation: string}>}> $catalogueRules
         *     - Catalogue rows as published by the JSON renderer.
         */
        $catalogueRules  = (array)(is_array($listPayload) ? ($listPayload['rules'] ?? []) : []);
        $catalogueShapes = array_merge(...array_map(
            static fn(array $rule): array => $rule['falsePositiveShapes'] ?? [],
            array_values(array_filter(
                $catalogueRules,
                static fn(array $rule): bool => $rule['id'] === 'waste.commented-out-code',
            )),
        ));
        $detailShapes = (array)(is_array($detailPayload) ? ($detailPayload['falsePositiveShapes'] ?? []) : []);

        self::assertSame(0, $listProcess->getExitCode(), $listProcess->getErrorOutput());
        self::assertSame(0, $detailProcess->getExitCode(), $detailProcess->getErrorOutput());
        self::assertNotEmpty($catalogueShapes);

        self::assertSame($detailShapes, $catalogueShapes);
    }

    /**
     * Verify requested rule detail JSON preserves toggle and additive option contracts.
     *
     * @return void - Asserts selected rule IDs, default options and exact option descriptions in detail JSON.
     */
    public function testRequestedRuleDetailsExposeDocumentedOptionContracts(): void
    {
        $expectedByRule = [
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
     * @return void - Asserts exit code 2 and the unknown-rule message, suggestion prompt and corrected ID.
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
     * Verifies that a package copy runs CLI help after installing dependencies without development packages.
     *
     * @return void - Asserts successful dependency installation and a successful help response containing Description:.
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
     * @return void - Asserts installation without a package autoloader and successful config creation through vendor/bin.
     * @throws \JsonException - If the consumer Composer manifest cannot be encoded as JSON.
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

    /**
     * Verifies selected named threshold maps in catalogue JSON and the method-length map in detail JSON.
     * Checks that maps are nonempty, omit severity keys and omit thresholds for header injection.
     *
     * @return void - Asserts selected catalogue maps, threshold-key absence and the method-length detail map.
     */
    public function testCatalogueAndDetailPublishThresholdsAsNamedKnobMaps(): void
    {
        $listProcess = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list-rules', '--format', 'json']);
        $listProcess->run();
        $detailProcess = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'size.method-length',
            '--format',
            'json',
        ]);
        $detailProcess->run();

        $listPayload   = json_decode($listProcess->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        $detailPayload = json_decode($detailProcess->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        /** @var list<array{id: string, thresholds?: array<string, int|float>}> $rules - Catalogue rows as published by the JSON renderer. */
        $rules = (array)(is_array($listPayload) ? ($listPayload['rules'] ?? []) : []);
        /** @var array{thresholds?: array<string, int|float>} $detail - Detail card as published by the JSON renderer. */
        $detail = is_array($detailPayload) ? $detailPayload : [];
        $rulesById = array_combine(
            array_map(static fn(array $rule): string => $rule['id'], $rules),
            $rules,
        );
        $pairShaped = array_keys(array_filter(
            $rulesById,
            static fn(array $rule): bool => array_key_exists('severity', $rule['thresholds'] ?? []),
        ));
        $emptyMaps = array_keys(array_filter(
            $rulesById,
            static fn(array $rule): bool => array_key_exists('thresholds', $rule) && $rule['thresholds'] === [],
        ));

        self::assertSame(0, $listProcess->getExitCode(), $listProcess->getErrorOutput());
        self::assertSame(0, $detailProcess->getExitCode(), $detailProcess->getErrorOutput());

        self::assertSame(['maxComplexity' => 20], $rulesById['complexity.cognitive']['thresholds'] ?? null);
        self::assertSame(['maxLines' => 100], $rulesById['size.method-length']['thresholds'] ?? null);

        self::assertSame(['threshold' => 10], $rulesById['docs.todo-density']['thresholds'] ?? null);
        self::assertSame(['minWords' => 2], $rulesById['test-quality.testdox-readability']['thresholds'] ?? null);
        self::assertArrayNotHasKey('thresholds', $rulesById['security.header-injection'] ?? []);
        self::assertSame([], $pairShaped);
        self::assertSame([], $emptyMaps);

        self::assertSame(['maxLines' => 100], $detail['thresholds'] ?? null);
    }
}
