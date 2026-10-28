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
 * Pins the per-rule `everyCallable` option: off by default, and when on the docs rules also check closures and
 * arrow functions bound to a name, documented by the docblock above the assignment. On `docs.missing-phpdoc` the
 * option adds undocumented named functions to the methods it always checks.
 */
final class EveryCallableOptionTest extends DocsRuleTestCase
{
    /**
     * A bound closure with a bare `@return` and an untagged parameter (line 10), a bound arrow function with a bare
     * `@param` (line 21), and an unbound arrow function passed as an argument (line 23) that is never checked.
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
     * Each docs rule that accepts the switch declares it off by default.
     *
     * @return void - asserts the declared default option
     */
    public function testEveryCallableDefaultsToOff(): void
    {
        foreach ([new MissingParamTagRule(), new ReturnCommentRule(), new ParamCommentRule()] as $rule) {
            self::assertFalse($rule->definition()->defaultOptions['everyCallable'], $rule->definition()->id);
        }
    }

    /**
     * Default settings leave bound closures unchecked, so no rule reports on this source.
     *
     * @return void - asserts the default findings stay empty
     */
    public function testDefaultSettingsIgnoreBoundClosures(): void
    {
        self::assertSame([], self::symbols($this->analyseSourceRule(self::SOURCE, MissingParamTagRule::ID)));
        self::assertSame([], self::symbols($this->analyseSourceRule(self::SOURCE, ReturnCommentRule::ID)));
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
     * With the switch on, a bound closure's bare `@return` tag is reported; the described arrow function is not.
     *
     * @return void - asserts the return-comment finding on the bound closure
     */
    public function testReturnCommentChecksBoundClosure(): void
    {
        self::assertSame(['Closure@10'], self::symbols($this->analyseWithEveryCallable(ReturnCommentRule::ID, true)));
    }

    /**
     * With the switch on, a bound arrow function's bare `@param` tag is reported; the unbound arrow function is not.
     *
     * @return void - asserts the param-comment finding on the bound arrow function
     */
    public function testParamCommentChecksBoundArrowFunction(): void
    {
        self::assertSame(['Closure@21'], self::symbols($this->analyseWithEveryCallable(ParamCommentRule::ID, true)));
    }

    /**
     * With the switch on, a bound closure whose docblock holds only bare tags is reported by both describing rules,
     * because docs.bare-phpdoc-tags never reads closures.
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
     * `docs.missing-phpdoc` declares the switch off by default.
     *
     * @return void - asserts the declared default option
     */
    public function testMissingPhpdocEveryCallableDefaultsToOff(): void
    {
        self::assertFalse((new MissingPhpdocRule())->definition()->defaultOptions['everyCallable']);
    }

    /**
     * Default settings keep `docs.missing-phpdoc` on methods only, so the undocumented named function is not reported.
     *
     * @return void - asserts only the undocumented method is reported, with its unchanged message
     */
    public function testMissingPhpdocDefaultSkipsNamedFunctions(): void
    {
        $findings = $this->analyseSourceRule(self::FUNCTION_SOURCE, MissingPhpdocRule::ID);

        self::assertSame(['Counter::current()'], self::symbols($findings));
        self::assertSame(
            'Method Counter::current() needs a brief intent description above its declaration (one plain-English line; not a restatement of the method signature). Also describe the return value.',
            $findings[0]->message,
        );
    }

    /**
     * With the switch on, `docs.missing-phpdoc` also reports the undocumented named function; the documented one is not.
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
     * Analyses a fixture with one rule enabled and its `everyCallable` option set.
     *
     * @param string $ruleId        - rule to enable and analyse with
     * @param bool   $everyCallable - value for the rule's `everyCallable` option
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
     * Lists each finding's symbol in report order.
     *
     * @param list<Finding> $findings - findings to summarise
     *
     * @return list<string> - the symbol of each finding
     */
    private static function symbols(array $findings): array
    {
        return array_map(static fn(Finding $finding): string => (string)$finding->symbol, $findings);
    }
}
