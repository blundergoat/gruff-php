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
 * Detects PHPUnit configs where deprecations do not fail the suite.
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
     * Describe the PHPUnit deprecations-not-fatal rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
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
     * Report a project once when PHPUnit deprecations do not fail the run.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit used to decide whether the project has PHPUnit tests.
     * @param RuleContext  $ruleContext  Rule context carrying project root.
     * @return list<Finding> Findings for non-fatal PHPUnit deprecations.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $root = $ruleContext->projectRoot;
        if (isset($this->emittedRoots[$root])) {
            return [];
        }

        if (!TestQualityNodeHelper::looksLikePhpUnitTestFile($analysisUnit)) {
            return [];
        }

        $config = $this->discovery->discover($root);
        if ($config === null) {
            return [];
        }

        $this->emittedRoots[$root] = true;

        $attributes     = $config->root->attributes();
        $attributeValue = $attributes !== null ? $attributes->failOnDeprecation : null;

        if ($attributeValue !== null && strtolower($attributeValue->__toString()) !== 'false' && $attributeValue->__toString() !== '') {
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
