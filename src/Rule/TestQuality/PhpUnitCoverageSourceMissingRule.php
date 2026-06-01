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
    private readonly PhpUnitConfigDiscovery $discovery;

    /** @var array<string, true> */
    private array $emittedRoots = [];

    /**
     * Create the rule with injectable PHPUnit config discovery for tests.
     *
     * @param PhpUnitConfigDiscovery|null $discovery - Discovery service override for tests.
     */
    public function __construct(?PhpUnitConfigDiscovery $discovery = null)
    {
        $this->discovery = $discovery ?? new PhpUnitConfigDiscovery();
    }

    /**
     * Describe the PHPUnit coverage source rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory severity so teams opt in: a missing coverage source is a gap, not a defect in shipped code.
        return new RuleDefinition(
            id:              self::ID,
            name:            'PHPUnit coverage source missing',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Report a project once when its PHPUnit config lacks coverage source configuration.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit used to decide whether the project has PHPUnit tests.
     * @param RuleContext  $ruleContext - Rule context carrying project root.
     *
     * @return list<Finding> - Findings for missing PHPUnit coverage source settings.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $root = $ruleContext->projectRoot;
        if (isset($this->emittedRoots[$root])) {
            // One config maps to many test files; emit per project root once so the finding is not duplicated.
            return [];
        }

        if (!TestQualityNodeHelper::looksLikePhpUnitTestFile($analysisUnit)) {
            // Wait for an actual PHPUnit test file before judging the config, so non-test projects stay silent.
            return [];
        }

        $config = $this->discovery->discover($root);
        if ($config === null) {
            // No discoverable phpunit.xml means there is no coverage config to fault; treat as not applicable.
            return [];
        }

        $this->emittedRoots[$root] = true;

        if ($this->hasCoverageSource($config->root)
            || isset($config->root->filter->whitelist) && $config->root->filter->whitelist->count() > 0
        ) {
            // Either a modern <source> or a legacy <filter><whitelist> scopes coverage, so the config is fine.
            return [];
        }

        // Neither include mechanism is present, so coverage would measure nothing meaningful; report it once.
        return [
            new Finding(
                ruleId:  self::ID,
                message: sprintf(
                    '%s does not declare a coverage <source> (or legacy <filter><whitelist>) include path.',
                    $config->displayPath,
                ),
                filePath:    $config->displayPath,
                line:        1,
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                symbol:      $config->displayPath,
                remediation: 'Declare a <source><include><directory>src</directory></include></source> block (PHPUnit 10+) or <filter><whitelist> (PHPUnit 9) so coverage measures the right files.',
            ),
        ];
    }

    /**
     * Check for PHPUnit 10 coverage source declarations.
     *
     * @param \SimpleXMLElement $root - Parsed <phpunit> root element whose coverage children are probed.
     *
     * @return bool - True when a supported coverage source/include block exists.
     */
    private function hasCoverageSource(\SimpleXMLElement $root): bool
    {
        if (isset($root->source) && $root->source->count() > 0) {
            // Newer schemas place <source> directly on the root; its presence alone scopes coverage.
            return true;
        }

        if (isset($root->coverage->source) && $root->coverage->source->count() > 0) {
            // Some schemas nest <source> under <coverage>; accept that placement as equivalent.
            return true;
        }

        if (isset($root->coverage->include) && $root->coverage->include->count() > 0) {
            // The older <coverage><include> form still declares an include path, so honour it too.
            return true;
        }

        // None of the supported include shapes matched, so coverage scope is undeclared.
        return false;
    }

}
