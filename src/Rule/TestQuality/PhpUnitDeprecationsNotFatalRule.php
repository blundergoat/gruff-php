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

final class PhpUnitDeprecationsNotFatalRule implements RuleInterface
{
    public const ID = 'test-quality.phpunit-deprecations-not-fatal';

    private PhpUnitConfigDiscovery $discovery;

    /** @var array<string, true> */
    private array $emittedRoots = [];

    /**
     * Create the rule with injectable PHPUnit config discovery for tests.
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
            id: self::ID,
            name: 'PHPUnit deprecations not fatal',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    /**
     * Report a project once when PHPUnit deprecations do not fail the run.
     *
     * @return list<Finding> Findings for non-fatal PHPUnit deprecations.
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

        $attributes = $config->root->attributes();
        $value = $attributes !== null ? $attributes->failOnDeprecation : null;

        if ($value !== null && strtolower($value->__toString()) !== 'false' && $value->__toString() !== '') {
            return [];
        }

        return [
            new Finding(
                ruleId: self::ID,
                message: sprintf(
                    '%s does not enable failOnDeprecation, so deprecated calls will not fail the test run.',
                    $config->displayPath,
                ),
                filePath: $config->displayPath,
                line: 1,
                severity: Severity::Warning,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                symbol: $config->displayPath,
                remediation: 'Add failOnDeprecation="true" to the <phpunit> root so deprecation notices fail the build instead of accumulating silently.',
            ),
        ];
    }
}
