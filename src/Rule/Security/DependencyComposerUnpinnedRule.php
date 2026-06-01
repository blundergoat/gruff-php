<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\SourceTextRuleInterface;

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
     * @return RuleDefinition Rule metadata and defaults.
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for unpinned constraints.
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
        foreach (self::REQUIRE_SECTIONS as $section) {
            if (!isset($manifest[$section]) || !is_array($manifest[$section])) {
                continue;
            }

            foreach ($manifest[$section] as $package => $constraint) {
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

        // One finding per unpinned constraint; empty when every require entry is bounded.
        return $findings;
    }

    /**
     * Decide whether a version constraint is unpinned (wildcard, branch, or unbounded).
     *
     * @param string $constraint Raw Composer version constraint.
     * @return bool True when the constraint allows unbounded or non-reproducible upgrades.
     */
    private function isUnpinned(string $constraint): bool
    {
        $normalized = strtolower(trim($constraint));

        if ($normalized === '*' || $normalized === '') {
            // A wildcard or empty constraint accepts any published version, so the install is non-reproducible.
            return true;
        }

        if (str_starts_with($normalized, 'dev-')) {
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
