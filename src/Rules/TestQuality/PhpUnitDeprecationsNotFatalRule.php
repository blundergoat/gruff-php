<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Project\PhpUnitConfigDiscovery;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;

/**
 * Checks discovered PHPUnit configuration for failOnDeprecation.
 * Reports at most once per project root after a path-based test-file match.
 * Findings use warning severity and high confidence.
 */
final class PhpUnitDeprecationsNotFatalRule implements RuleInterface
{
    /**
     * Stable identifier for the PHPUnit deprecation-fail rule.
     */
    public const ID = 'test-quality.phpunit-deprecations-not-fatal';

    /**
     * Config discovery collaborator cached for repeated project scans.
     */
    private readonly PhpUnitConfigDiscovery $discovery;

    /** @var array<string, true> - Project roots already evaluated using discovered configuration. */
    private array $emittedRoots = [];

    /**
     * Creates the rule with injectable PHPUnit config discovery for tests.
     *
     * @param PhpUnitConfigDiscovery|null $discovery - Discovery service override for tests, or null to use the default discovery.
     */
    public function __construct(?PhpUnitConfigDiscovery $discovery = null)
    {
        $this->discovery = $discovery ?? new PhpUnitConfigDiscovery();
    }

    /**
     * Describes the phpunit-deprecations-not-fatal rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {

        return new RuleDefinition(
            id:              self::ID,
            name:            'PHPUnit deprecations not fatal',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports a project once when PHPUnit deprecations do not fail the run.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit used to decide whether the project has PHPUnit tests.
     * @param RuleContext  $ruleContext - Rule context carrying project root.
     *
     * @return list<Finding> - Findings for non-fatal PHPUnit deprecations.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $root = $ruleContext->projectRoot;
        if (isset($this->emittedRoots[$root])) {
            // One config maps to many test files; emit per project root once so the finding is not duplicated.
            return [];
        }

        if (!TestQualityNodeHelper::looksLikePhpUnitTestFile($analysisUnit)) {
            // Defer configuration checks until a path matches the PHPUnit test-file heuristic.
            return [];
        }

        $config = $this->discovery->discover($root);
        if ($config === null) {
            // Missing or unparseable PHPUnit configuration leaves this rule without settings to inspect.
            return [];
        }

        $this->emittedRoots[$root] = true;

        $attributes     = $config->root->attributes();
        $attributeValue = $attributes !== null ? $attributes->failOnDeprecation : null;

        if ($attributeValue !== null && strtolower($attributeValue->__toString()) !== 'false' && $attributeValue->__toString() !== '') {
            // A present, non-empty, non-"false" value means deprecations already fail the run; nothing to report.
            return [];
        }


        return [
            new Finding(
                ruleId:  self::ID,
                message: sprintf(
                    '%s does not enable failOnDeprecation, so deprecated calls will not fail the test run.',
                    $config->displayPath,
                ),
                filePath:    $config->displayPath,
                line:        1,
                severity:    Severity::Warning,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                symbol:      $config->displayPath,
                remediation: 'Add failOnDeprecation="true" to the <phpunit> root so deprecation notices fail the build instead of accumulating silently.',
            ),
        ];
    }
}
