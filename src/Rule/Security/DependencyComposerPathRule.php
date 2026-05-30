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
 * Flags Composer `path` repositories that link local code into the dependency tree.
 */
final class DependencyComposerPathRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for Composer path-repository findings.
     */
    public const ID = 'security.dependency-composer-path';

    /**
     * Describe the Composer path-repository rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Composer path repository',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find `repositories` entries of type `path`.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for path repositories.
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
            if ($type !== 'path') {
                continue;
            }

            $anchor = isset($repository['url']) && is_string($repository['url']) ? $repository['url'] : '"type"';
            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Composer repository of type \'path\' links local code into the dependency tree; ensure the path is trusted and intentional, as path repositories can symlink code from outside the project.',
                filePath:    $analysisUnit->file->displayPath,
                line:        ComposerManifest::lineOf($analysisUnit->source, $anchor),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Confirm the path repository points to code you control; for shared internal packages prefer a private Packagist/Composer registry with version constraints.',
                metadata:    [
                    'repositoryType' => 'path',
                ],
            );
        }

        return $findings;
    }
}
