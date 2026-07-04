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
 * Flags a Composer VCS repository (git, svn, and the like), which resolves a dependency straight from a
 * version-control source outside Packagist - so the user verifies the source is trusted and pinned.
 *
 * Scans `composer.json` as text over each `repositories` entry whose type pulls from version control.
 * Warning, medium confidence - a VCS source is a supply-chain smell to review, not proof of harm.
 */
final class DependencyComposerVcsRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for Composer VCS-repository findings.
     */
    public const ID = 'security.dependency-composer-vcs';

    /**
     * Composer repository types that pull code straight from version control.
     *
     * @var list<string>
     */
    private const VCS_TYPES = ['vcs', 'git', 'svn', 'hg', 'fossil', 'perforce'];

    /**
     * Describes the Composer VCS-repository rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence by default: a VCS repository is a supply-chain smell to review, not proof of harm.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Composer VCS repository',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Reports each Composer repository that resolves dependencies from version control.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per VCS repository; empty when every repository resolves through Packagist.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ComposerManifest::isManifest($analysisUnit->file->displayPath)) {
            // This rule only applies to composer.json; every other file yields no findings.
            return [];
        }

        $manifest = ComposerManifest::decode($analysisUnit->source);
        if ($manifest === null || !isset($manifest['repositories']) || !is_array($manifest['repositories'])) {
            // Unparseable manifest or no repositories block means there is nothing of this shape to flag.
            return [];
        }

        $findings = [];
        // Check each declared repository entry.
        foreach ($manifest['repositories'] as $repository) {
            // A malformed, non-object entry cannot be a VCS repository.
            if (!is_array($repository)) {
                continue;
            }

            $type = isset($repository['type']) && is_string($repository['type']) ? strtolower($repository['type']) : '';
            // Only a version-control repository type is flagged.
            if (!in_array($type, self::VCS_TYPES, true)) {
                continue;
            }

            $anchor = isset($repository['url']) && is_string($repository['url']) ? $repository['url'] : $type;
            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf("Composer repository of type '%s' resolves dependencies from a version-control source outside Packagist; verify the source is trusted and pinned.", $type),
                filePath:    $analysisUnit->file->displayPath,
                line:        ComposerManifest::lineOf($analysisUnit->source, $anchor),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Prefer Packagist-published, version-constrained dependencies; if a VCS source is required, pin it to an immutable commit and review its supply chain.',
                metadata:    [
                    'repositoryType' => $type,
                ],
            );
        }

        return $findings;
    }
}
