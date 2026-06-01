<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

use GruffPhp\Config\RuleSettings;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use JsonException;

/**
 * Holds ownership and escape-hatch decisions for project-wide dead-code rules.
 */
final readonly class DeadCodeProjectScope
{
    /**
     * Capture one analysis pass's project-wide dead-code scope.
     *
     * @param list<string>        $internalNamespacePrefixes   Project-owned namespace prefixes.
     * @param array<string, true> $entrypointSymbols           Exact symbols treated as externally invoked.
     * @param list<string>        $entrypointPathPrefixes      Paths whose declarations are external entrypoints.
     * @param list<string>        $additionalExcludedPaths     Paths excluded from declaration/reference indexing.
     * @param list<string>        $externalNamespacePrefixes   Namespace prefixes treated as external contracts.
     * @param list<string>        $frameworkAttributePrefixes  Lowercase attribute prefixes that mark entrypoints.
     * @param bool                $shouldTreatTestsAsReferences Whether test references keep production symbols live.
     */
    private function __construct(
        private array $internalNamespacePrefixes,
        private array $entrypointSymbols,
        private array $entrypointPathPrefixes,
        private array $additionalExcludedPaths,
        private array $externalNamespacePrefixes,
        private array $frameworkAttributePrefixes,
        private bool $shouldTreatTestsAsReferences,
    ) {
    }

    /**
     * Build project-wide dead-code scope from rule options and composer metadata.
     *
     * @param RuleContext    $ruleContext Project root and effective config.
     * @param RuleDefinition $definition  Rule definition whose options drive scope.
     *
     * @return self - configured scope for one analysis pass
     */
    public static function fromContext(RuleContext $ruleContext, RuleDefinition $definition): self
    {
        $settings = $ruleContext->settingsFor($definition);

        $treatTestsAsReferences = $settings->option('treatTestsAsReferences');

        return new self(
            internalNamespacePrefixes:   self::internalNamespacePrefixes($ruleContext, $settings),
            entrypointSymbols:           self::symbolSet($settings->stringListOption('entrypointSymbols')),
            entrypointPathPrefixes:      self::pathPrefixes($settings->stringListOption('entrypointPathPrefixes')),
            additionalExcludedPaths:     self::pathPrefixes($settings->stringListOption('additionalExcludedPaths')),
            externalNamespacePrefixes:   self::namespacePrefixes(
                $settings->stringListOption('externalNamespacePrefixes'),
            ),
            frameworkAttributePrefixes:  array_map(
                static fn(string $prefix): string => strtolower(ltrim($prefix, '\\')),
                $settings->stringListOption('frameworkAttributePrefixes'),
            ),
            shouldTreatTestsAsReferences: is_bool($treatTestsAsReferences) ? $treatTestsAsReferences : true,
        );
    }

    /**
     * Decide whether project ownership is known for this pass.
     *
     * @return bool - true when composer/config supplied at least one internal namespace prefix
     */
    public function hasInternalOwnership(): bool
    {
        return $this->internalNamespacePrefixes !== [];
    }

    /**
     * Decide whether references from tests keep production symbols live.
     *
     * @return bool - true when test references are counted as live references
     */
    public function shouldTreatTestsAsReferences(): bool
    {
        return $this->shouldTreatTestsAsReferences;
    }

    /**
     * Decide whether a FQN is project-owned.
     *
     * @param string $fqn Symbol FQN without a leading slash.
     *
     * @return bool - true only when the FQN matches an internal prefix and no external prefix
     */
    public function isInternalFqn(string $fqn): bool
    {
        foreach ($this->externalNamespacePrefixes as $externalPrefix) {
            if (str_starts_with($fqn, $externalPrefix)) {
                return false;
            }
        }

        foreach ($this->internalNamespacePrefixes as $internalPrefix) {
            if (str_starts_with($fqn, $internalPrefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a whole unit is excluded from project-wide dead-code indexing.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit.
     *
     * @return bool - true when the unit path matches an exclusion prefix
     */
    public function isExcludedUnit(AnalysisUnit $analysisUnit): bool
    {
        foreach ($this->additionalExcludedPaths as $prefix) {
            if (str_starts_with($analysisUnit->file->displayPath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether declarations in a path are configured entrypoints.
     *
     * @param string $displayPath Project-relative path.
     *
     * @return bool - true when the path is under an entrypoint prefix
     */
    public function isEntrypointPath(string $displayPath): bool
    {
        foreach ($this->entrypointPathPrefixes as $prefix) {
            if (str_starts_with($displayPath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a symbol is configured as an entrypoint.
     *
     * @param string $fqn Symbol FQN without a leading slash.
     *
     * @return bool - true when the exact symbol is configured as externally invoked
     */
    public function isEntrypointSymbol(string $fqn): bool
    {
        return isset($this->entrypointSymbols[$fqn]);
    }

    /**
     * Decide whether attributes mark a declaration as a framework entrypoint.
     *
     * @param DeadCodeSymbolDeclaration $declaration Declaration to inspect.
     *
     * @return bool - true when any attribute starts with a configured framework prefix
     */
    public function hasFrameworkAttribute(DeadCodeSymbolDeclaration $declaration): bool
    {
        foreach ($declaration->attributes as $attribute) {
            $attribute = strtolower(ltrim($attribute, '\\'));
            foreach ($this->frameworkAttributePrefixes as $prefix) {
                if ($prefix !== '' && str_starts_with($attribute, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Decide whether a path is a test file.
     *
     * @param string $displayPath Project-relative path.
     *
     * @return bool - true for conventional test directories or test filenames
     */
    public function isTestPath(string $displayPath): bool
    {
        return str_contains($displayPath, '/tests/')
            || str_starts_with($displayPath, 'tests/')
            || str_ends_with($displayPath, 'Test.php');
    }

    /**
     * Derive internal namespace prefixes from explicit config or composer PSR-4 autoload.
     *
     * @param RuleContext  $ruleContext Project root for composer.json.
     * @param RuleSettings $settings    Rule settings carrying explicit prefixes.
     *
     * @return list<string> - normalized namespace prefixes; empty means no project-wide findings are allowed
     */
    private static function internalNamespacePrefixes(RuleContext $ruleContext, RuleSettings $settings): array
    {
        $explicitPrefixes = self::namespacePrefixes($settings->stringListOption('internalNamespacePrefixes'));
        if ($explicitPrefixes !== []) {
            return $explicitPrefixes;
        }

        $composerPath = rtrim($ruleContext->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerPath)) {
            return [];
        }

        $contents = file_get_contents($composerPath);
        if ($contents === false) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $prefixes = [];
        foreach (['autoload', 'autoload-dev'] as $sectionName) {
            $section = $decoded[$sectionName] ?? null;
            if (!is_array($section)) {
                continue;
            }

            $psr4 = $section['psr-4'] ?? null;
            if (!is_array($psr4)) {
                continue;
            }

            foreach (array_keys($psr4) as $prefix) {
                if (is_string($prefix)) {
                    $prefixes[] = $prefix;
                }
            }
        }

        return self::namespacePrefixes($prefixes);
    }

    /**
     * Normalize namespace prefixes.
     *
     * @param list<string> $prefixes Configured or composer-derived prefixes.
     *
     * @return list<string> - non-empty prefixes without leading slash and with trailing slash
     */
    private static function namespacePrefixes(array $prefixes): array
    {
        $normalized = [];
        foreach ($prefixes as $prefix) {
            $prefix = ltrim($prefix, '\\');
            if ($prefix === '') {
                continue;
            }

            $normalized[$prefix] = str_ends_with($prefix, '\\') ? $prefix : $prefix . '\\';
        }

        return array_values($normalized);
    }

    /**
     * Normalize path prefixes.
     *
     * @param list<string> $prefixes Configured path prefixes.
     *
     * @return list<string> - prefixes without leading slash or empty values
     */
    private static function pathPrefixes(array $prefixes): array
    {
        $normalized = [];
        foreach ($prefixes as $prefix) {
            $prefix = ltrim($prefix, '/');
            if ($prefix !== '') {
                $normalized[] = $prefix;
            }
        }

        return $normalized;
    }

    /**
     * Normalize symbol names to a lookup set.
     *
     * @param list<string> $symbols Configured exact entrypoint symbols.
     *
     * @return array<string, true> - exact symbol lookup table
     */
    private static function symbolSet(array $symbols): array
    {
        $symbolLookup = [];
        foreach ($symbols as $symbol) {
            $symbol = ltrim($symbol, '\\');
            if ($symbol !== '') {
                $symbolLookup[$symbol] = true;
            }
        }

        return $symbolLookup;
    }
}
