<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Project\PhpUnitConfigDiscovery;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;

/**
 * Detects PHPUnit configs without explicit coverage source paths.
 */
final class PhpUnitCoverageSourceMissingRule implements RuleInterface
{
    /**
     * Stable identifier for the PHPUnit coverage-source rule.
     */
    public const ID = 'test-quality.phpunit-coverage-source-missing';

    /**
     * Config discovery collaborator cached for repeated project scans.
     */
    private PhpUnitConfigDiscovery $discovery;

    /** @var array<string, true> */
    private array $emittedRoots = [];

    /**
     * Create the rule with injectable PHPUnit config discovery for tests.
     *
     * @param PhpUnitConfigDiscovery|null $discovery Discovery service override for tests.
     */
    public function __construct(?PhpUnitConfigDiscovery $discovery = null)
    {
        $this->discovery = $discovery ?? new PhpUnitConfigDiscovery();
    }

    /**
     * Describe the PHPUnit coverage source rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'PHPUnit coverage source missing',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    /**
     * Report a project once when its PHPUnit config lacks coverage source configuration.
     *
     * @param AnalysisUnit $unit Parsed unit used to decide whether the project has PHPUnit tests.
     * @param RuleContext $context Rule context carrying project root.
     * @return list<Finding> Findings for missing PHPUnit coverage source settings.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $root = $context->projectRoot;
        if (isset($this->emittedRoots[$root])) {
            return [];
        }

        if (!TestQualityNodeHelper::looksLikePhpUnitTestFile($unit)) {
            return [];
        }

        $config = $this->discovery->discover($root);
        if ($config === null) {
            return [];
        }

        $this->emittedRoots[$root] = true;

        if ($this->hasCoverageSource($config->root) || $this->hasLegacyWhitelist($config->root)) {
            return [];
        }

        return [
            new Finding(
                ruleId: self::ID,
                message: sprintf(
                    '%s does not declare a coverage <source> (or legacy <filter><whitelist>) include path.',
                    $config->displayPath,
                ),
                filePath: $config->displayPath,
                line: 1,
                severity: Severity::Advisory,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::Medium,
                symbol: $config->displayPath,
                remediation: 'Declare a <source><include><directory>src</directory></include></source> block (PHPUnit 10+) or <filter><whitelist> (PHPUnit 9) so coverage measures the right files.',
            ),
        ];
    }

    /**
     * Check for PHPUnit 10 coverage source declarations.
     *
     * @return bool True when a supported coverage source/include block exists.
     */
    private function hasCoverageSource(\SimpleXMLElement $root): bool
    {
        if (isset($root->source) && $root->source->count() > 0) {
            return true;
        }

        if (isset($root->coverage->source) && $root->coverage->source->count() > 0) {
            return true;
        }

        if (isset($root->coverage->include) && $root->coverage->include->count() > 0) {
            return true;
        }

        return false;
    }

    /**
     * Check for PHPUnit 9 legacy whitelist declarations.
     *
     * @return bool True when a legacy filter whitelist exists.
     */
    private function hasLegacyWhitelist(\SimpleXMLElement $root): bool
    {
        return isset($root->filter->whitelist) && $root->filter->whitelist->count() > 0;
    }
}
