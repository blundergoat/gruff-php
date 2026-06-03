<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\DeadCode;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\DeadCode\UnusedInternalClassRule;
use GruffPhp\Rule\DeadCode\UnusedInternalConstantRule;
use GruffPhp\Rule\DeadCode\UnusedInternalFunctionRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers project-wide dead-code rules over multi-file symbol-reference fixtures.
 */
final class ProjectDeadCodeRulesTest extends TestCase
{
    /**
     * Fixture project root containing composer PSR-4 metadata.
     */
    private const FIXTURE_ROOT = __DIR__ . '/../../Fixtures/DeadCode/project-wide';

    /**
     * Fixture files analysed as one project.
     *
     * @var list<string>
     */
    private const FIXTURE_FILES = [
        'src/Symbols.php',
        'src/Controller/RouteControllers.php',
        'src/references.php',
        'tests/TestReferences.php',
        'entrypoints/Entrypoints.php',
        'src/FrameworkCommand.php',
        'src/External/Vendored.php',
        'config/routes/inline.yaml',
        'config/routes/block.yml',
        'config/routes/quoted.yaml',
        'config/routes/non-fqcn.yaml',
    ];

    /**
     * Verify unused internal class-like declarations are reported.
     *
     * @return void
     */
    public function testUnusedInternalClassLikeDeclarationsDetected(): void
    {
        $symbols = $this->symbolsForRule(UnusedInternalClassRule::ID);

        self::assertContains('App\\UnusedClass', $symbols);
        self::assertContains('App\\UnusedTrait', $symbols);
        self::assertContains('App\\UnusedEnum', $symbols);
        self::assertContains('App\\SelfOnlyClass', $symbols);
        self::assertContains('App\\PathEntrypoint', $symbols);
        self::assertContains('App\\External\\Vendored', $symbols);
        self::assertNotContains('App\\UsedClass', $symbols);
        self::assertNotContains('App\\UsedTrait', $symbols);
        self::assertNotContains('App\\TraitConsumer', $symbols);
        self::assertNotContains('App\\AliasReferencedClass', $symbols);
        self::assertNotContains('App\\ClassConstantReferencedClass', $symbols);
        self::assertNotContains('App\\StaticReferencedClass', $symbols);
        self::assertNotContains('App\\TypeReferencedClass', $symbols);
        self::assertNotContains('App\\ImplementedInterface', $symbols);
        self::assertNotContains('App\\InterfaceConsumer', $symbols);
        self::assertNotContains('App\\FixtureAttribute', $symbols);
        self::assertNotContains('App\\UsedEnum', $symbols);
        self::assertNotContains('App\\TestOnlyClass', $symbols);
        self::assertNotContains('App\\FrameworkCommand', $symbols);
        self::assertNotContains('App\\Tests\\FixtureTestCase', $symbols);
    }

    /**
     * Verify Symfony YAML `_controller` FQCN callables keep route controllers live.
     *
     * @return void
     */
    public function testSymfonyYamlControllerReferencesKeepInternalClassesLive(): void
    {
        $symbols = $this->symbolsForRule(UnusedInternalClassRule::ID);

        self::assertNotContains('App\\Controller\\InlineController', $symbols);
        self::assertNotContains('App\\Controller\\BlockController', $symbols);
        self::assertNotContains('App\\Controller\\SingleQuotedController', $symbols);
        self::assertNotContains('App\\Controller\\DoubleQuotedController', $symbols);
        self::assertContains('App\\Controller\\UnreferencedController', $symbols);
        self::assertContains('App\\Controller\\ServiceIdStyleController', $symbols);
        self::assertContains('App\\Controller\\OtherKeyController', $symbols);
    }

    /**
     * Verify unused internal functions are reported while direct and test references count.
     *
     * @return void
     */
    public function testUnusedInternalFunctionsDetected(): void
    {
        $symbols = $this->symbolsForRule(UnusedInternalFunctionRule::ID);

        self::assertContains('App\\unused_function', $symbols);
        self::assertContains('App\\recursive_unused_function', $symbols);
        self::assertContains('App\\path_entrypoint_function', $symbols);
        self::assertNotContains('App\\used_function', $symbols);
        self::assertNotContains('App\\test_only_function', $symbols);
        self::assertNotContains('App\\first_class_callable_function', $symbols);
    }

    /**
     * Verify unused internal standalone constants are reported while direct and test references count.
     *
     * @return void
     */
    public function testUnusedInternalConstantsDetected(): void
    {
        $symbols = $this->symbolsForRule(UnusedInternalConstantRule::ID);

        self::assertSame(['App\\UNUSED_CONSTANT'], $symbols);
    }

    /**
     * Verify test references can be ignored by option.
     *
     * @return void
     */
    public function testTreatTestsAsReferencesOptionCanBeDisabled(): void
    {
        $classSymbols = $this->symbolsForRule(
            UnusedInternalClassRule::ID,
            $this->configWithOptions(UnusedInternalClassRule::ID, ['treatTestsAsReferences' => false]),
        );
        $functionSymbols = $this->symbolsForRule(
            UnusedInternalFunctionRule::ID,
            $this->configWithOptions(UnusedInternalFunctionRule::ID, ['treatTestsAsReferences' => false]),
        );
        $constantSymbols = $this->symbolsForRule(
            UnusedInternalConstantRule::ID,
            $this->configWithOptions(UnusedInternalConstantRule::ID, ['treatTestsAsReferences' => false]),
        );

        self::assertContains('App\\TestOnlyClass', $classSymbols);
        self::assertContains('App\\test_only_function', $functionSymbols);
        self::assertContains('App\\TEST_ONLY_CONSTANT', $constantSymbols);
    }

    /**
     * Verify symbol, path, framework, and external-prefix escape hatches are scoped.
     *
     * @return void
     */
    public function testProjectWideDeadCodeEscapeHatchesAreScoped(): void
    {
        $entrypointSymbols = $this->symbolsForRule(
            UnusedInternalClassRule::ID,
            $this->configWithOptions(UnusedInternalClassRule::ID, ['entrypointSymbols' => ['App\\UnusedClass']]),
        );
        $entrypointPathSymbols = $this->symbolsForRule(
            UnusedInternalClassRule::ID,
            $this->configWithOptions(UnusedInternalClassRule::ID, ['entrypointPathPrefixes' => ['entrypoints/']]),
        );
        $excludedPathSymbols = $this->symbolsForRule(
            UnusedInternalClassRule::ID,
            $this->configWithOptions(UnusedInternalClassRule::ID, ['additionalExcludedPaths' => ['entrypoints/']]),
        );
        $externalSymbols = $this->symbolsForRule(
            UnusedInternalClassRule::ID,
            $this->configWithOptions(UnusedInternalClassRule::ID, ['externalNamespacePrefixes' => ['App\\External\\']]),
        );
        $withoutFrameworkExemption = $this->symbolsForRule(
            UnusedInternalClassRule::ID,
            $this->configWithOptions(UnusedInternalClassRule::ID, ['frameworkAttributePrefixes' => []]),
        );

        self::assertNotContains('App\\UnusedClass', $entrypointSymbols);
        self::assertContains('App\\UnusedTrait', $entrypointSymbols);
        self::assertNotContains('App\\PathEntrypoint', $entrypointPathSymbols);
        self::assertContains('App\\UnusedClass', $entrypointPathSymbols);
        self::assertNotContains('App\\PathEntrypoint', $excludedPathSymbols);
        self::assertNotContains('App\\External\\Vendored', $externalSymbols);
        self::assertContains('App\\FrameworkCommand', $withoutFrameworkExemption);
    }

    /**
     * Verify missing composer ownership prevents project-wide findings unless prefixes are configured.
     *
     * @return void
     */
    public function testMissingComposerEmitsNoFindingsUnlessInternalPrefixesConfigured(): void
    {
        $projectRoot = $this->tempDir();

        try {
            self::assertTrue(mkdir($projectRoot . '/src', 0777, true));
            file_put_contents($projectRoot . '/src/Orphan.php', "<?php\n\nnamespace App;\n\nfinal class Orphan {}\n");
            $units = [$this->parseProjectFile($projectRoot, 'src/Orphan.php')];

            self::assertSame([], $this->symbolsForRuleWithUnits(UnusedInternalClassRule::ID, $units, $projectRoot));

            $config  = $this->configWithOptions(
                UnusedInternalClassRule::ID,
                ['internalNamespacePrefixes' => ['App\\']],
            );
            $symbols = $this->symbolsForRuleWithUnits(UnusedInternalClassRule::ID, $units, $projectRoot, $config);

            self::assertSame(['App\\Orphan'], $symbols);
        } finally {
            $this->removeDir($projectRoot);
        }
    }

    /**
     * Analyse the fixture and return symbols for one rule.
     *
     * @param string              $ruleId - Rule identifier to filter.
     * @param AnalysisConfig|null $config - Optional config override.
     *
     * @return list<string> - symbols emitted by that rule in report order
     */
    private function symbolsForRule(string $ruleId, ?AnalysisConfig $config = null): array
    {
        return $this->symbolsForRuleWithUnits($ruleId, $this->fixtureUnits(), self::FIXTURE_ROOT, $config);
    }

    /**
     * Analyse units and return symbols for one rule.
     *
     * @param string              $ruleId - Rule identifier to filter.
     * @param list<AnalysisUnit>  $units - Parsed units to analyse together.
     * @param string              $projectRoot - Project root used by rule context.
     * @param AnalysisConfig|null $config - Optional config override.
     *
     * @return list<string> - symbols emitted by that rule in report order
     */
    private function symbolsForRuleWithUnits(
        string $ruleId,
        array $units,
        string $projectRoot,
        ?AnalysisConfig $config = null,
    ): array {
        $registry = RuleRegistry::defaults();
        $config   ??= AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse($units, new RuleContext($projectRoot, $config));
        $filtered = array_values(array_filter(
            $findings,
            static fn(Finding $finding): bool => $finding->ruleId === $ruleId,
        ));

        // Symbols are the stable user-facing identity for dead-code findings.
        return array_map(static fn(Finding $finding): string => $finding->symbol ?? '', $filtered);
    }

    /**
     * Build a config override for a rule's options.
     *
     * @param string                                                                 $ruleId - Rule identifier.
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options - Option overrides to merge over defaults.
     *
     * @return AnalysisConfig - config with the requested rule options changed
     */
    private function configWithOptions(string $ruleId, array $options): AnalysisConfig
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $settings = $config->ruleSettings($ruleId);

        return $config->withRuleSettings(
            $ruleId,
            new RuleSettings(
                enabled:           $settings->enabled,
                thresholds:        $settings->thresholds,
                options:           array_merge($settings->options, $options),
                severityThreshold: $settings->severityThreshold,
                excludeFromScore:  $settings->excludeFromScore,
            ),
        );
    }

    /**
     * Parse fixture project files.
     *
     * @return list<AnalysisUnit> - parsed project fixture units
     */
    private function fixtureUnits(): array
    {
        return array_map(
            fn(string $displayPath): AnalysisUnit => $this->parseProjectFile(self::FIXTURE_ROOT, $displayPath),
            self::FIXTURE_FILES,
        );
    }

    /**
     * Parse one project-relative PHP file.
     *
     * @param string $projectRoot - Project root containing the file.
     * @param string $displayPath - Project-relative display path.
     *
     * @return AnalysisUnit - parsed unit with display path relative to $projectRoot
     */
    private function parseProjectFile(string $projectRoot, string $displayPath): AnalysisUnit
    {
        $type = str_ends_with($displayPath, '.php') ? SourceFile::TYPE_PHP : SourceFile::TYPE_TEXT;

        return (new PhpFileParser())->parse(new SourceFile($projectRoot . '/' . $displayPath, $displayPath, $type));
    }

    /**
     * Create a temporary directory.
     *
     * @return string - absolute path to a new temp directory
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-project-dead-code-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($path));

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path - Directory path.
     *
     * @return void
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);
        foreach ($items as $directoryEntry) {
            if ($directoryEntry === '.' || $directoryEntry === '..') {
                continue;
            }

            $child = $path . '/' . $directoryEntry;
            if (is_dir($child)) {
                $this->removeDir($child);
                continue;
            }

            self::assertTrue(unlink($child));
        }

        self::assertTrue(rmdir($path));
    }
}
