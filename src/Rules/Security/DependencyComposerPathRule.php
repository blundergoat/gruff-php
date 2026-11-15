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
 * Flags Composer `path` repositories for trust review without resolving their targets or checking how Composer installs them.
 * Scans `composer.json` and emits one warning per matched repository with medium confidence.
 * A type match does not establish a vulnerability.
 */
final class DependencyComposerPathRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for Composer path-repository findings.
     */
    public const ID = 'security.dependency-composer-path';

    /**
     * Describes the Composer path-repository rule for the registry and reports.
     *
     * @return RuleDefinition - metadata `list-rules` publishes; the rule ships off by default, so only a config can turn it on
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence by default: a path repo is a smell to review, not proof of a vulnerability.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Composer path repository',
            pillar:             Pillar::Security,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Warning,
            confidence:         Confidence::Medium,
            // Off unless a project sets `enabled: true`: the 0.6.0 precision measurement found both judged findings wrong (ADR-034).
            // Two findings are too few to delete the rule on, so it stays available for projects that want it.
            isEnabledByDefault: false,
            falsePositiveShapes: [
                [
                    'shape'      => 'A monorepo that develops its own first-party packages through path repositories pointing inside the same reviewed checkout.',
                    'mitigation' => 'Every path repository is reported because the linked target is not resolved, so confirm the path is first-party and accept the finding.',
                ],
            ],
        );
    }

    /**
     * Reports each Composer `path` repository declared in the manifest.
     *
     * @param AnalysisUnit $analysisUnit - Source unit supplying the manifest path and JSON text.
     * @param RuleContext $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per path repository; empty for other files, invalid input or no matching entries.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ComposerManifest::isManifest($analysisUnit->file->displayPath)) {

            return [];
        }

        $manifest = ComposerManifest::decode($analysisUnit->source);
        if ($manifest === null || !isset($manifest['repositories']) || !is_array($manifest['repositories'])) {
            // Unparseable manifest or no repositories block means there is nothing of this shape to flag.
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
