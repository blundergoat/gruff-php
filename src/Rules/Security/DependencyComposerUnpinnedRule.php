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
 * Flags an unpinned Composer dependency constraint - `*`, a `dev-` branch, or an open `>=` with no upper
 * bound - so the user pins it before an unattended `composer update` pulls unvetted or breaking code.
 *
 * Scans `composer.json` as text over the require and require-dev sections. Warning, medium confidence - an
 * unbounded constraint is a reproducibility smell to review, not a proven risk.
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
     * @return list<Finding> - One finding per unpinned constraint; empty when every require entry is bounded.
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
        // Check both the require and require-dev sections.
        foreach (self::REQUIRE_SECTIONS as $section) {
            // Skip a section the manifest does not declare.
            if (!isset($manifest[$section]) || !is_array($manifest[$section])) {
                continue;
            }

            // Weigh each package constraint in the section.
            foreach ($manifest[$section] as $package => $constraint) {
                // Only a string package with an unpinned constraint is flagged.
                if (!is_string($package) || !is_string($constraint) || !$this->isUnpinned($constraint)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf("Unpinned dependency constraint '%s' for %s allows non-reproducible or unexpected upgrades; pin to a bounded version range.", $constraint, $package),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        ComposerManifest::lineOf($analysisUnit->source, sprintf('"%s"', $package)),
                    severity:    Severity::Warning,
                    pillar:      Pillar::Security,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Medium,
                    remediation: 'Constrain the dependency with a bounded operator (for example "^1.2" or ">=1.2,<2.0") and avoid "*", "dev-" branches, and open-ended ">=" requirements.',
                    metadata:    [
                        'package'    => $package,
                        'constraint' => $constraint,
                    ],
                );
            }
        }

        return $findings;
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
