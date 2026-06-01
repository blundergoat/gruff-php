<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Project\PhpUnitConfig;
use GruffPhp\Project\PhpUnitConfigDiscovery;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;

/**
 * Detects PHPUnit configs that omit strict feedback flags.
 */
final class PhpUnitStrictFlagsMissingRule implements RuleInterface
{
    /**
     * Stable identifier for the PHPUnit strict flags rule.
     */
    public const ID = 'test-quality.phpunit-strict-flags-missing';

    /**
     * PHPUnit attributes that make risky or noisy tests fail visibly.
     */
    private const STRICT_FLAGS = [
        'failOnRisky',
        'failOnWarning',
        'beStrictAboutTestsThatDoNotTestAnything',
        'beStrictAboutOutputDuringTests',
        'beStrictAboutChangesToGlobalState',
    ];

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
     * Describe the PHPUnit strict flags rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning severity at high confidence: each missing strict attribute is a concrete, unambiguous gap.
        return new RuleDefinition(
            id:              self::ID,
            name:            'PHPUnit strict flags missing',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Report a project once when PHPUnit strict-mode attributes are missing.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit used to decide whether the project has PHPUnit tests.
     * @param RuleContext  $ruleContext  Rule context carrying project root.
     * @return list<Finding> Findings for missing strict PHPUnit flags.
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
            // No discoverable phpunit.xml means there are no strict attributes to fault; treat as not applicable.
            return [];
        }

        $missing = $this->missingFlags($config);
        if ($missing === []) {
            $this->emittedRoots[$root] = true;

            // Every strict flag is set, so this root is fully configured; later test files need no re-check.
            return [];
        }

        $this->emittedRoots[$root] = true;

        // At least one strict flag is absent, so risky/noisy tests could pass unnoticed; report the gap once.
        return [
            new Finding(
                ruleId:  self::ID,
                message: sprintf(
                    '%s is missing strict-mode attribute(s): %s.',
                    $config->displayPath,
                    implode(', ', $missing),
                ),
                filePath:    $config->displayPath,
                line:        1,
                severity:    Severity::Warning,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                symbol:      $config->displayPath,
                remediation: 'Add the missing attributes to the <phpunit> root so risky, warning, no-assertion, output, and global-state test smells fail the build.',
                metadata:    ['missing' => $missing],
            ),
        ];
    }

    /**
     * List PHPUnit strictness flags missing from configuration.
     *
     * @param PhpUnitConfig $config Discovered config whose <phpunit> root attributes are checked for each flag.
     * @return list<string>
     */
    private function missingFlags(PhpUnitConfig $config): array
    {
        $attributes = $config->root->attributes();
        $missing    = [];

        foreach (self::STRICT_FLAGS as $flag) {
            $flagValue = $attributes !== null ? $attributes->{$flag} : null;
            if ($flagValue === null || $flagValue->__toString() === '' || strtolower($flagValue->__toString()) === 'false') {
                $missing[] = $flag;
            }
        }

        // Names of flags that are unset, blank, or "false"; an empty list means strictness is fully configured.
        return $missing;
    }
}
