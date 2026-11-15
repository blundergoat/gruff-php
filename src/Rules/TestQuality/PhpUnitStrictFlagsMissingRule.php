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
 * Checks discovered PHPUnit configuration for explicitly required failure and strictness attributes.
 * Reports at most once per project root after a path-based test-file match.
 * Findings use warning severity and high confidence.
 */
final class PhpUnitStrictFlagsMissingRule implements RuleInterface
{
    /**
     * Stable identifier for the PHPUnit strict flags rule.
     */
    public const ID = 'test-quality.phpunit-strict-flags-missing';

    /**
     * PHPUnit failure and strictness attributes required explicitly by this rule.
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
     * Describes the phpunit-strict-flags-missing rule for the registry and reports.
     *
     * @return RuleDefinition - the rule's identity, pillar, tier, and default severity/confidence used by the registry
     */
    public function definition(): RuleDefinition
    {

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
     * @param AnalysisUnit $analysisUnit - Parsed unit whose path gates the PHPUnit test-file heuristic.
     * @param RuleContext  $ruleContext - Rule context carrying project root.
     *
     * @return list<Finding> - One finding naming absent strict flags per root; empty when inspection is skipped or finds none.
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

        $missing = $this->missingFlags($config);

        if ($missing === []) {
            $this->emittedRoots[$root] = true;

            // Every strict flag is set, so this root is fully configured; later test files need no re-check.
            return [];
        }

        $this->emittedRoots[$root] = true;


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
