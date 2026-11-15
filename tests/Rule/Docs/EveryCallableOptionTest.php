<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Rules\Docs\MissingParamTagRule;
use GruffPhp\Rules\Docs\MissingPhpdocRule;
use GruffPhp\Rules\Docs\ParamCommentRule;
use GruffPhp\Rules\Docs\ReturnCommentRule;
use GruffPhp\Rules\RuleRegistry;

/**
 * Pins the per-rule `everyCallable` defaults and explicit opt-outs; the wider docs rules check closures and
 * arrow functions bound to a name, documented by the docblock above the assignment. On `docs.missing-phpdoc` the
 * option adds undocumented named functions to the methods it always checks.
 */
final class EveryCallableOptionTest extends DocsRuleTestCase
{
    /**
     * A bound closure has a bare `@return` and an untagged parameter; a bound arrow function has a bare `@param`.
     * An unbound arrow function is passed directly to array_map.
     */
    private const SOURCE = <<<'PHP'
<?php

/**
 * Builds the greeting line.
 *
 * @param string $name Display name of the visitor.
 *
 * @return string
 */
$formatGreeting = static function (string $name, string $salutation): string {
    return $salutation . ', ' . $name . '.';
};

/**
 * Doubles a count for the report.
 *
 * @param int $count
 *
 * @return int The doubled count.
 */
$double = fn (int $count): int => $count * 2;

$unbound = array_map(fn (int $number): int => $number, [1]);
PHP;

    /**
     * An undocumented named function, a documented named function, and an undocumented method.
     */
    private const FUNCTION_SOURCE = <<<'PHP'
<?php

function undocumentedHelper(int $count): int
{
    return $count;
}

/**
 * Returns the count unchanged.
 */
function documentedHelper(int $count): int
{
    return $count;
}

final class Counter
{
    public function current(): int
    {
        return 1;
    }
}
PHP;

    /**
     * Missing-parameter and return rules include every callable by default; parameter descriptions do not.
     *
     * @return void - asserts the declared default option
     */
    public function testEveryCallableDefaultsMatchEachRule(): void
    {
        foreach ([new MissingParamTagRule(), new ReturnCommentRule()] as $rule) {
            self::assertTrue($rule->definition()->defaultOptions['everyCallable'], $rule->definition()->id);
        }
        self::assertFalse((new ParamCommentRule())->definition()->defaultOptions['everyCallable']);
    }

    /**
     * Default settings report the bound closure for missing parameter tags and return descriptions.
     * Explicit false yields no findings from those rules or the parameter-description rule for this fixture.
     *
     * @return void - asserts the default finding symbols and empty results with everyCallable set to false
     */
    public function testDefaultSettingsCheckBoundClosuresAndAllowOptOut(): void
    {
        self::assertSame(['Closure@10'], self::symbols($this->analyseSourceRule(self::SOURCE, MissingParamTagRule::ID)));
        self::assertSame(['Closure@10'], self::symbols($this->analyseSourceRule(self::SOURCE, ReturnCommentRule::ID)));
        self::assertSame([], self::symbols($this->analyseWithEveryCallable(MissingParamTagRule::ID, false)));
        self::assertSame([], self::symbols($this->analyseWithEveryCallable(ReturnCommentRule::ID, false)));
        self::assertSame([], self::symbols($this->analyseWithEveryCallable(ParamCommentRule::ID, false)));
    }

    /**
     * With the switch on, a bound closure's untagged parameter is reported against its symbol.
     *
     * @return void - asserts the missing-param-tag finding on the bound closure
     */
    public function testMissingParamTagChecksBoundClosure(): void
    {
        $findings = $this->analyseWithEveryCallable(MissingParamTagRule::ID, true);

        self::assertSame(['Closure@10'], self::symbols($findings));
        self::assertSame('salutation', $findings[0]->metadata['parameter'] ?? null);
    }

    /**
     * With the switch on, a bound closure's bare @return tag is reported; the described arrow function is not.
     *
     * @return void - asserts the return-comment finding on the bound closure
     */
    public function testReturnCommentChecksBoundClosure(): void
    {
        self::assertSame(['Closure@10'], self::symbols($this->analyseWithEveryCallable(ReturnCommentRule::ID, true)));
    }

    /**
     * With the switch on, a bound arrow function's bare @param tag is reported; the unbound arrow function is not.
     *
     * @return void - asserts the param-comment finding on the bound arrow function
     */
    public function testParamCommentChecksBoundArrowFunction(): void
    {
        self::assertSame(['Closure@21'], self::symbols($this->analyseWithEveryCallable(ParamCommentRule::ID, true)));
    }

    /**
     * With the switch on, both parameter-description and return-description rules report a closure whose docblock has only bare tags.
     *
     * @return void - asserts param-comment and return-comment both report the bare-tags closure
     */
    public function testBareTagsOnlyClosureIsReported(): void
    {
        $source = <<<'PHP'
<?php

/**
 * @param string $name
 *
 * @return string
 */
$label = static function (string $name): string {
    return $name;
};
PHP;

        self::assertSame(['Closure@8'], self::symbols($this->analyseWithEveryCallable(ParamCommentRule::ID, true, $source)));
        self::assertSame(['Closure@8'], self::symbols($this->analyseWithEveryCallable(ReturnCommentRule::ID, true, $source)));
    }

    /**
     * docs.missing-phpdoc includes every callable by default.
     *
     * @return void - asserts the declared default option
     */
    public function testMissingPhpdocEveryCallableDefaultsToOn(): void
    {
        self::assertTrue((new MissingPhpdocRule())->definition()->defaultOptions['everyCallable']);
    }

    /**
     * Default settings report undocumented named functions; explicit false keeps method-only checking.
     *
     * @return void - asserts the default findings and the explicit method-only opt-out
     */
    public function testMissingPhpdocDefaultChecksNamedFunctionsAndAllowsOptOut(): void
    {
        $findings = $this->analyseSourceRule(self::FUNCTION_SOURCE, MissingPhpdocRule::ID);

        self::assertSame(['undocumentedHelper()', 'Counter::current()'], self::symbols($findings));
        self::assertSame(['Counter::current()'], self::symbols($this->analyseWithEveryCallable(MissingPhpdocRule::ID, false, self::FUNCTION_SOURCE)));
        self::assertSame(
            'Method Counter::current() needs a brief intent description above its declaration (one plain-English line; not a restatement of the method signature). Also describe the return value.',
            $findings[1]->message,
        );
    }

    /**
     * With the switch on, docs.missing-phpdoc also reports the undocumented named function; the documented one is not.
     *
     * @return void - asserts the named-function finding and its message
     */
    public function testMissingPhpdocChecksNamedFunctions(): void
    {
        $findings = $this->analyseWithEveryCallable(MissingPhpdocRule::ID, true, self::FUNCTION_SOURCE);

        self::assertSame(['undocumentedHelper()', 'Counter::current()'], self::symbols($findings));
        self::assertSame(
            'Function undocumentedHelper() needs a brief intent description above its declaration (one plain-English line; not a restatement of the function signature). Also describe parameter $count and the return value.',
            $findings[0]->message,
        );
    }

    /**
     * Analyses a fixture with the selected rule enabled and its everyCallable option set.
     *
     * @param string $ruleId        - rule to enable and analyse with
     * @param bool   $everyCallable - value for the rule's everyCallable option
     * @param string $source        - PHP source to analyse; the bound-closure fixture unless a test passes another
     *
     * @return list<Finding> - findings the rule reports on the fixture
     */
    private function analyseWithEveryCallable(string $ruleId, bool $everyCallable, string $source = self::SOURCE): array
    {
        $config   = AnalysisConfig::fromRegistry(RuleRegistry::defaults());
        $settings = $config->ruleSettings($ruleId);
        $config   = $config->withRuleSettings(
            $ruleId,
            new RuleSettings(
                true,
                $settings->thresholds,
                [...$settings->options, 'everyCallable' => $everyCallable],
            ),
        );

        return $this->analyseSourceRule($source, $ruleId, $config);
    }

    /**
     * List finding symbols in report order, converting null symbols to empty strings.
     *
     * @param list<Finding> $findings - findings to summarise
     *
     * @return list<string> - finding symbols in report order; null symbols become empty strings
     */
    private static function symbols(array $findings): array
    {
        return array_map(static fn(Finding $finding): string => (string)$finding->symbol, $findings);
    }
}
