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
 * Flags Composer VCS repositories that resolve dependencies outside Packagist.
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
     * Describe the Composer VCS-repository rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * Find `repositories` entries that resolve dependencies from version control.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for VCS repositories.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ComposerManifest::isManifest($analysisUnit->file->displayPath)) {
            return [];
        }

        $manifest = ComposerManifest::decode($analysisUnit->source);
        if ($manifest === null || !isset($manifest['repositories']) || !is_array($manifest['repositories'])) {
            return [];
        }

        $findings = [];
        foreach ($manifest['repositories'] as $repository) {
            if (!is_array($repository)) {
                continue;
            }

            $type = isset($repository['type']) && is_string($repository['type']) ? strtolower($repository['type']) : '';
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
