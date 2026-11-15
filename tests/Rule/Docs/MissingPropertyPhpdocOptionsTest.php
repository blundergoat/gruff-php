<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Rules\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rules\RuleRegistry;

/**
 * Exercises missing-property findings with acceptLineComments disabled and enabled.
 * Contrasts attached descriptive line comments with generic, detached, trailing and block comments.
 * Checks that one attached comment can cover both names in a grouped property declaration.
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

    // Property fillerName
    public string $fillerName = '';

    // Cache
    public array $singleWord = [];

    // Value retained for the next request.

    public string $detached = '';

    /** Already documented. */
    public string $documented = '';

    public bool $plainOwner = false; // Follows from the previous declaration.
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
     * @return void - Asserts the default and opt-in finding sets for attached, detached, generic, block and trailing comments.
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
            'PropertyCommentFixture::$fillerName',
            'PropertyCommentFixture::$first',
            'PropertyCommentFixture::$generic',
            'PropertyCommentFixture::$hashAttached',
            'PropertyCommentFixture::$plainOwner',
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
            'PropertyCommentFixture::$fillerName',
            'PropertyCommentFixture::$generic',
            'PropertyCommentFixture::$plainOwner',
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
     * @return list<string> - One symbol per finding in lexical order; null symbols become empty strings.
     */
    private static function symbols(array $findings): array
    {
        $symbols = array_map(static fn ($finding): string => $finding->symbol ?? '', $findings);
        sort($symbols);

        return $symbols;
    }
}
