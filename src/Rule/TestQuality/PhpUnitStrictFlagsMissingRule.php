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

final class PhpUnitStrictFlagsMissingRule implements RuleInterface
{
    public const ID = 'test-quality.phpunit-strict-flags-missing';

    private const STRICT_FLAGS = [
        'failOnRisky',
        'failOnWarning',
        'beStrictAboutTestsThatDoNotTestAnything',
        'beStrictAboutOutputDuringTests',
        'beStrictAboutChangesToGlobalState',
    ];

    private PhpUnitConfigDiscovery $discovery;

    /** @var array<string, true> */
    private array $emittedRoots = [];

    public function __construct(?PhpUnitConfigDiscovery $discovery = null)
    {
        $this->discovery = $discovery ?? new PhpUnitConfigDiscovery();
    }

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'PHPUnit strict flags missing',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

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

        $missing = $this->missingFlags($config);
        if ($missing === []) {
            $this->emittedRoots[$root] = true;
            return [];
        }

        $this->emittedRoots[$root] = true;

        return [
            new Finding(
                ruleId: self::ID,
                message: sprintf(
                    '%s is missing strict-mode attribute(s): %s.',
                    $config->displayPath,
                    implode(', ', $missing),
                ),
                filePath: $config->displayPath,
                line: 1,
                severity: Severity::Warning,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                symbol: $config->displayPath,
                remediation: 'Add the missing attributes to the <phpunit> root so risky, warning, no-assertion, output, and global-state test smells fail the build.',
                metadata: ['missing' => $missing],
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function missingFlags(PhpUnitConfig $config): array
    {
        $attributes = $config->root->attributes();
        $missing = [];

        foreach (self::STRICT_FLAGS as $flag) {
            $value = $attributes !== null ? $attributes->{$flag} : null;
            if ($value === null || $value->__toString() === '' || strtolower($value->__toString()) === 'false') {
                $missing[] = $flag;
            }
        }

        return $missing;
    }
}
