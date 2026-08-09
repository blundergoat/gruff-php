<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Rules\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rules\RuleRegistry;

/**
 * Covers configuration-specific behavior for property documentation findings.
 */
final class MissingPropertyPhpdocOptionsTest extends DocsRuleTestCase
{
    /** Inline property declarations spanning accepted and rejected local-comment shapes. */
    private const SOURCE = <<<'PHP'
<?php
final class PropertyCommentFixture
{
    // Tenant identifier used for access-control decisions.
    public string $attached = '';

    # Retry count used to stop transient loops.
    public int $hashAttached = 0;

    // Property value
    public string $generic = '';

    // Restated property
    public string $restatedProperty = '';

    // Cache
    public array $singleWord = [];

    // Value retained for the next request.

    public string $detached = '';

    /** Already documented. */
    public string $documented = ''; // Follows from the previous declaration.
    public string $trailingFollower = '';

    /* Cache key shared across workers. */
    public string $block = '';

    // Values cached for the next request.
    public string $first = '', $second = '';
}
PHP;

    /**
     * Verify the line-comment option requires physically attached, meaning-bearing line prose.
     *
     * @return void
     */
    public function testLineCommentOptionRequiresAttachedMeaningfulLineProse(): void
    {
        $defaultSymbols = self::symbols($this->analyseSourceRule(
            self::SOURCE,
            MissingPropertyPhpdocRule::ID,
        ));

        self::assertSame([
            'PropertyCommentFixture::$attached',
            'PropertyCommentFixture::$block',
            'PropertyCommentFixture::$detached',
            'PropertyCommentFixture::$first',
            'PropertyCommentFixture::$generic',
            'PropertyCommentFixture::$hashAttached',
            'PropertyCommentFixture::$restatedProperty',
            'PropertyCommentFixture::$second',
            'PropertyCommentFixture::$singleWord',
            'PropertyCommentFixture::$trailingFollower',
        ], $defaultSymbols);

        $registry        = RuleRegistry::defaults();
        $config          = AnalysisConfig::fromRegistry($registry);
        $settings        = $config->ruleSettings(MissingPropertyPhpdocRule::ID);
        $acceptingConfig = $config->withRuleSettings(
            MissingPropertyPhpdocRule::ID,
            new RuleSettings(
                true,
                $settings->thresholds,
                [...$settings->options, 'acceptLineComments' => true],
            ),
        );
        $acceptingSymbols = self::symbols($this->analyseSourceRule(
            self::SOURCE,
            MissingPropertyPhpdocRule::ID,
            $acceptingConfig,
        ));

        self::assertSame([
            'PropertyCommentFixture::$block',
            'PropertyCommentFixture::$detached',
            'PropertyCommentFixture::$generic',
            'PropertyCommentFixture::$restatedProperty',
            'PropertyCommentFixture::$singleWord',
            'PropertyCommentFixture::$trailingFollower',
        ], $acceptingSymbols);
    }

    /**
     * Return finding symbols in stable lexical order.
     *
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Property findings to summarize.
     *
     * @return list<string> - Sorted finding symbols; a missing symbol becomes an empty string and fails expectations.
     */
    private static function symbols(array $findings): array
    {
        $symbols = array_map(static fn ($finding): string => $finding->symbol ?? '', $findings);
        sort($symbols);

        return $symbols;
    }
}
