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
 * Flags unpinned Composer version constraints that allow non-reproducible upgrades.
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
     * Describe the unpinned Composer constraint rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
     * Find `require`/`require-dev` constraints that are unbounded or wildcarded.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per unpinned constraint; empty when every require entry is bounded.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // User view: choose the findings list branch for this case.
        if (!ComposerManifest::isManifest($analysisUnit->file->displayPath)) {
            // This rule only applies to composer.json; every other file yields no findings.
            return [];
        }

        $manifest = ComposerManifest::decode($analysisUnit->source);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($manifest === null) {
            // Unparseable manifest means there are no declared constraints to inspect.
            return [];
        }

        $findings = [];
        // User view: add each item that can appear in findings list.
        foreach (self::REQUIRE_SECTIONS as $section) {
            // User view: choose the findings list branch for this case.
            if (!isset($manifest[$section]) || !is_array($manifest[$section])) {
                continue;
            }

            // User view: add each item that can appear in findings list.
            foreach ($manifest[$section] as $package => $constraint) {
                // User view: choose the findings list branch for this case.
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
     * Decide whether a version constraint is unpinned (wildcard, branch, or unbounded).
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $constraint - Raw Composer version constraint.
     *
     * @return bool - True when the constraint allows unbounded or non-reproducible upgrades.
     */
    private function isUnpinned(string $constraint): bool
    {
        $normalized = strtolower(trim($constraint));

        // User view: add each item that can appear in findings list.
        foreach (preg_split('/\s*\|\|?\s*/', $normalized) ?: [$normalized] as $alternative) {
            // User view: choose the findings list branch for this case.
            if ($this->isUnpinnedAlternative($alternative)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a single Composer constraint alternative is unpinned.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $constraint - One OR-separated Composer version alternative.
     *
     * @return bool - True when the alternative is wildcarded, branch-based, or missing an upper bound.
     */
    private function isUnpinnedAlternative(string $constraint): bool
    {
        $normalized = trim($constraint);

        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($normalized === '*' || $normalized === '') {
            // A wildcard or empty constraint accepts any published version, so the install is non-reproducible.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if (str_contains($normalized, '*')) {
            return true;
        }

        // User view: choose the findings list branch for this case.
        if (str_starts_with($normalized, 'dev-') || str_ends_with($normalized, '@dev')) {
            // A dev- branch tracks a moving HEAD, not a release, so resolved code can change silently.
            return true;
        }

        // An open lower bound with no upper bound (">=1.0" / ">1.0") is non-reproducible;
        // a bounded range ("<2.0" present) or caret/tilde operator is considered pinned.
        // User view: choose the findings list branch for this case.
        if ((str_contains($normalized, '>=') || str_contains($normalized, '>')) && !str_contains($normalized, '<')) {
            // Future major releases satisfy this constraint, so an upgrade can pull breaking or unvetted code.
            return true;
        }

        // Everything else carries an upper bound (caret, tilde, or explicit range), so it is treated as pinned.
        return false;
    }
}
