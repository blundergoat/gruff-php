<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\SourceTextRuleInterface;

/**
 * Flags an installable Composer dependency using `*`, a `dev-` branch, or an open `>=` with no upper bound.
 *
 * Users see the warning on the exact `require` or `require-dev` entry they need to bound before a future update pulls unexpected code.
 * PHP, Composer, extension, and system-library platform requirements stay quiet because Composer does not install them as packages.
 * Warning, medium confidence - an unbounded constraint is a reproducibility smell to review, not a proven risk.
 */
final class DependencyComposerUnpinnedRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for unpinned Composer constraint findings.
     */
    public const ID = 'security.dependency-composer-unpinned';

    /**
     * `composer.json` keys that declare dependency version constraints.
     *
     * @var list<string>
     */
    private const REQUIRE_SECTIONS = ['require', 'require-dev'];

    /**
     * Describes the unpinned-Composer-constraint rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: an unbounded constraint is a reproducibility smell to review, not a proven risk.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unpinned Composer dependency constraint',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Reports each unpinned `require`/`require-dev` constraint.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per unpinned installable package; empty when every package is bounded or platform-provided.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ComposerManifest::isManifest($analysisUnit->file->displayPath)) {
            // This rule only applies to composer.json; every other file yields no findings.
            return [];
        }

        $manifest = ComposerManifest::decode($analysisUnit->source);
        if ($manifest === null) {
            // Unparseable manifest means there are no declared constraints to inspect.
            return [];
        }

        $findings = [];
        // Check both dependency sections users can edit in composer.json.
        foreach (self::REQUIRE_SECTIONS as $dependencySectionName) {
            // A missing or non-object section declares no package constraints for the user to review.
            if (!isset($manifest[$dependencySectionName]) || !is_array($manifest[$dependencySectionName])) {
                continue;
            }

            // Weigh each package constraint in the section the same way Composer presents it to the user.
            foreach ($manifest[$dependencySectionName] as $packageName => $constraint) {
                // Non-string entries are not usable Composer package constraints, so the rule has nothing reliable to report.
                if (!is_string($packageName) || !is_string($constraint)) {
                    continue;
                }

                // PHP, Composer APIs, extensions, and system libraries describe the install platform rather than downloaded packages.
                if ($this->isComposerPlatformPackage($packageName)) {
                    continue;
                }

                // A bounded installable package already gives the user a reproducible upgrade range.
                if (!$this->isUnpinned($constraint)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:  self::ID,
                    message: sprintf(
                        "Unpinned dependency constraint '%s' for %s allows non-reproducible or unexpected upgrades; "
                        . 'pin to a bounded version range.',
                        $constraint,
                        $packageName,
                    ),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        ComposerManifest::lineOfDependencyInSection($analysisUnit->source, $dependencySectionName, $packageName),
                    severity:    Severity::Warning,
                    pillar:      Pillar::Security,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Medium,
                    remediation: 'Constrain the dependency with a bounded operator (for example "^1.2" or ">=1.2,<2.0") '
                        . 'and avoid "*", "dev-" branches, and open-ended ">=" requirements.',
                    metadata:    [
                        'package'    => $packageName,
                        'constraint' => $constraint,
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * Reports whether a requirement names PHP, Composer, an extension, or a system library supplied by the user's install platform.
     *
     * @param string $packageName - Composer package key; an empty or ordinary vendor/package name is not treated as platform-provided.
     *
     * @return bool - true when Composer validates the requirement against the platform instead of downloading that named package
     */
    private function isComposerPlatformPackage(string $packageName): bool
    {
        $normalizedPackageName  = strtolower($packageName);
        $isNamedPlatformPackage = in_array($normalizedPackageName, ['composer', 'composer-plugin-api', 'composer-runtime-api', 'php'], true);

        // Prefixes such as ext-json and php-64bit describe capabilities already supplied by the user's install platform.
        $hasPlatformPackagePrefix = preg_match('/^(?:ext-|lib-|php-)/', $normalizedPackageName) === 1;

        return $isNamedPlatformPackage || $hasPlatformPackagePrefix;
    }

    /**
     * Reports whether a version constraint is unpinned (wildcard, branch, or unbounded).
     *
     * @param string $constraint - Raw Composer version constraint.
     *
     * @return bool - True when the constraint allows unbounded or non-reproducible upgrades.
     */
    private function isUnpinned(string $constraint): bool
    {
        $normalized = strtolower(trim($constraint));

        // A constraint can be an OR of alternatives; check each one.
        foreach (preg_split('/\s*\|\|?\s*/', $normalized) ?: [$normalized] as $alternative) {
            // One unpinned alternative makes the whole constraint unpinned.
            if ($this->isUnpinnedAlternative($alternative)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a single Composer constraint alternative is unpinned.
     *
     * @param string $constraint - One OR-separated Composer version alternative.
     *
     * @return bool - True when the alternative is wildcarded, branch-based, or missing an upper bound.
     */
    private function isUnpinnedAlternative(string $constraint): bool
    {
        $normalized = trim($constraint);

        if ($normalized === '*' || $normalized === '') {
            // A wildcard or empty constraint accepts any published version, so the install is non-reproducible.
            return true;
        }

        // Any embedded wildcard accepts a whole range of versions.
        if (str_contains($normalized, '*')) {
            return true;
        }

        if (str_starts_with($normalized, 'dev-') || str_ends_with($normalized, '@dev')) {
            // A dev- branch tracks a moving HEAD, not a release, so resolved code can change silently.
            return true;
        }

        // An open lower bound with no upper bound (">=1.0" / ">1.0") is non-reproducible;
        // a bounded range ("<2.0" present) or caret/tilde operator is considered pinned.
        if ((str_contains($normalized, '>=') || str_contains($normalized, '>')) && !str_contains($normalized, '<')) {
            // Future major releases satisfy this constraint, so an upgrade can pull breaking or unvetted code.
            return true;
        }

        // Everything else carries an upper bound (caret, tilde, or explicit range), so it is treated as pinned.
        return false;
    }
}
