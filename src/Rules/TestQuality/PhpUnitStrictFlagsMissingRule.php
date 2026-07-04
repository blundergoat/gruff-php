<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Project\PhpUnitConfig;
use GruffPhp\Engine\Project\PhpUnitConfigDiscovery;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;

/**
 * Flags a PHPUnit config that omits strict-mode attributes (`failOnRisky`, `failOnWarning`,
 * `beStrictAboutTestsThatDoNotTestAnything`, ...) - without them, risky and noisy tests pass unnoticed and
 * the suite's signal erodes. Fires once per project when a PHPUnit test file is seen. Warning, high confidence.
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
     * Creates the rule with injectable PHPUnit config discovery for tests.
     *
     * @param PhpUnitConfigDiscovery|null $discovery - Discovery service override for tests, or null to use the default discovery.
     */
    public function __construct(?PhpUnitConfigDiscovery $discovery = null)
    {
        $this->discovery = $discovery ?? new PhpUnitConfigDiscovery();
    }

    /**
     * Describes the phpunit-strict-flags-missing rule for the registry and reports.
     *
     * @return RuleDefinition - the rule's identity, pillar, tier, and default severity/confidence used by the registry
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
     * Reports a project once when PHPUnit strict-mode attributes are missing.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit used to decide whether the project has PHPUnit tests.
     * @param RuleContext  $ruleContext - Rule context carrying project root.
     *
     * @return list<Finding> - one finding naming the absent strict flags, emitted once per project root; empty when fully configured or not a
     *                       PHPUnit project
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
        // When no flag is missing, mark the root checked and stay silent.
        if ($missing === []) {
            $this->emittedRoots[$root] = true;

            // Every strict flag is set, so this root is fully configured; later test files need no re-check.
            return [];
        }

        $this->emittedRoots[$root] = true;

        // At least one strict flag is absent, so risky/noisy tests could pass unnoticed; report the gap once.
        return [
            new Finding(
                ruleId:      self::ID,
                message:     sprintf(
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
     * Lists the PHPUnit strictness flags missing from configuration.
     *
     * @param PhpUnitConfig $config - Discovered config whose <phpunit> root attributes are checked for each flag.
     *
     * @return list<string> - missing strict flag names in STRICT_FLAGS order; empty when strictness is fully configured
     */
    private function missingFlags(PhpUnitConfig $config): array
    {
        $attributes = $config->root->attributes();
        $missing    = [];

        // Weigh each strict flag the config should set.
        foreach (self::STRICT_FLAGS as $flag) {
            $flagValue = $attributes !== null ? $attributes->{$flag} : null;
            // A flag that is absent, empty, or "false" is not enforced.
            if ($flagValue === null || $flagValue->__toString() === '' || strtolower($flagValue->__toString()) === 'false') {
                $missing[] = $flag;
            }
        }

        return $missing;
    }
}
