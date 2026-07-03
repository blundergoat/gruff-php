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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence by default: a path repo is a smell to review, not proof of a vulnerability.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per path repository; empty when every repository declared another type.
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
        if ($manifest === null || !isset($manifest['repositories']) || !is_array($manifest['repositories'])) {
            // Unparseable manifest or no repositories block means there is nothing of this shape to flag.
            return [];
        }

        $findings = [];
        // User view: add each item that can appear in findings list.
        foreach ($manifest['repositories'] as $repository) {
            // User view: choose the findings list branch for this case.
            if (!is_array($repository)) {
                continue;
            }

            $type = isset($repository['type']) && is_string($repository['type']) ? strtolower($repository['type']) : '';
            // User view: choose the findings list branch for this case.
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
