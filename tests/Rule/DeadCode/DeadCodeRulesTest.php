<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\DeadCode;

use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\DeadCode\UnusedPrivateConstantRule;
use GruffPhp\Rules\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Checks private-method and private-constant findings across class, trait and enum fixtures.
 * Covers used members, excluded visibility and conservative handling of dynamic dispatch.
 * Uses fixture configuration to enable private-method analysis in the default registry.
 */
final class DeadCodeRulesTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Create the parser used by the fixture analysis helpers.
     *
     * @return void - Stores a fresh parser for each test.
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify unused private methods detected.
     *
     * @return void - Asserts that exactly the two expected unused private methods are reported.
     */
    public function testUnusedPrivateMethodsDetected(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        self::assertCount(2, $findings);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('UnusedPrivateMethodFixture::unusedPrivate()', $symbols);
        self::assertContains('UnusedPrivateMethodFixture::alsoUnused()', $symbols);
    }

    /**
     * Verify used private method not flagged.
     *
     * @return void - Asserts that the expected directly called and callable-referenced methods are absent from findings.
     */
    public function testUsedPrivateMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivateMethodFixture::usedPrivate()', $symbols);
        self::assertNotContains('UnusedPrivateMethodFixture::comparePromptRowsByLabel()', $symbols);
        self::assertNotContains('UnusedPrivateMethodFixture::comparePromptRowsByScore()', $symbols);
        self::assertNotContains('UnusedPrivateMethodFixture::comparePromptRowsByType()', $symbols);
        self::assertNotContains('UnusedPrivateMethodFixture::normalisePromptRow()', $symbols);
        self::assertNotContains('UnusedPrivateMethodFixture::formatPromptRow()', $symbols);
    }

    /**
     * Check that computed same-class dispatch suppresses private-method findings in that scope.
     * Literal names and foreign receivers leave unrelated unused methods reportable.
     *
     * @return void - Asserts the expected reported and suppressed symbols and exactly four findings.
     */
    public function testDynamicPrivateDispatchSuppressesOnlyItsOwnClassLike(): void
    {
        $findings = $this->analyseRule('dynamic-private-dispatch.php', UnusedPrivateMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        // Computed $this->{$m}(), static::{$hook}(), and [$this, $method] each suppress their own class-like.
        self::assertNotContains('EventRouter::handleCreated()', $symbols);
        self::assertNotContains('EventRouter::handleDeleted()', $symbols);
        self::assertNotContains('HooksTrait::bootHook()', $symbols);
        self::assertNotContains('CallableMapRouter::mappedHandler()', $symbols);
        self::assertContains('AssociativeCallbackMetadataRouter::unusedMetadataHelper()', $symbols);
        self::assertNotContains('ReorderedCallableMapRouter::possibleTarget()', $symbols);
        // A string-literal dynamic name resolves precisely, so siblings still report...
        self::assertNotContains('LiteralDynamicCaller::invokeExact()', $symbols);
        self::assertContains('LiteralDynamicCaller::stillUnused()', $symbols);
        // A foreign receiver leaves its caller's unused method reportable; the enum's literal call leaves its unused sibling reportable.
        self::assertContains('ForeignDispatcher::neverCalled()', $symbols);
        self::assertNotContains('Signal::labelFor()', $symbols);
        self::assertContains('Signal::unusedInEnum()', $symbols);
        self::assertCount(4, $findings);
    }

    /**
     * Verify the fixture's private constructor is absent from private-method findings.
     *
     * @return void - Asserts that UnusedPrivateMethodFixture::__construct() is not reported.
     */
    public function testMagicMethodsExcluded(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivateMethodFixture::__construct()', $symbols);
    }

    /**
     * Verify the protected fixture method is absent from private-method findings.
     *
     * @return void - Asserts that UnusedPrivateMethodFixture::protectedMethod() is not reported.
     */
    public function testProtectedMethodNotChecked(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivateMethodFixture::protectedMethod()', $symbols);
    }

    /**
     * Verify unused private constants detected across class, trait, and enum scopes.
     *
     * @return void - Asserts that exactly the expected class, trait and enum constants are reported.
     */
    public function testUnusedPrivateConstantsDetected(): void
    {
        $findings = $this->analyseRule('unused-private-constant.php', UnusedPrivateConstantRule::ID);

        self::assertCount(3, $findings);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('UnusedPrivateConstantFixture::UNUSED', $symbols);
        self::assertContains('PrivateConstantTraitFixture::UNUSED_TRAIT', $symbols);
        self::assertContains('PrivateConstantEnumFixture::UNUSED_ENUM', $symbols);
    }

    /**
     * Verify literal private constant reads are not flagged.
     *
     * @return void - Asserts that constants referenced by literal reads and defaults are absent from findings.
     */
    public function testUsedPrivateConstantsNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-constant.php', UnusedPrivateConstantRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_BY_SELF', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_BY_STATIC', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_BY_THIS', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_BY_OWN_NAME', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_IN_DEFAULT', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_IN_ARRAY_DEFAULT', $symbols);
        self::assertNotContains('PrivateConstantTraitFixture::USED_TRAIT', $symbols);
        self::assertNotContains('PrivateConstantEnumFixture::USED_ENUM', $symbols);
    }

    /**
     * Verify non-private constants and dynamic-name scopes are not flagged.
     *
     * @return void - Asserts that the listed non-private, inherited and dynamically addressed constants are absent.
     */
    public function testNonPrivateAndDynamicConstantsNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-constant.php', UnusedPrivateConstantRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivateConstantFixture::PROTECTED_CONSTANT', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::PUBLIC_CONSTANT', $symbols);
        self::assertNotContains('DynamicPrivateConstantFixture::MAYBE_DYNAMIC', $symbols);
        self::assertNotContains('InheritedConstantParentFixture::INHERITED_PROTECTED', $symbols);
        self::assertNotContains('InheritedConstantParentFixture::INHERITED_PUBLIC', $symbols);
    }

    /**
     * Verify the clean fixture has no private-method or private-constant findings.
     *
     * @return void - Asserts that combining the two selected rules' findings yields an empty list.
     */
    public function testCleanFileHasNoDeadCodeFindings(): void
    {
        $findings = array_merge(
            $this->analyseRule('clean.php', UnusedPrivateMethodRule::ID),
            $this->analyseRule('clean.php', UnusedPrivateConstantRule::ID),
        );

        self::assertSame([], $findings);
    }

    /**
     * Analyse dead-code fixtures and return findings for assertions.
     *
     * @param string $fixture - Fixture filename under tests/Fixtures/DeadCode.
     * @param string $ruleId - Rule ID whose findings are retained after configured registry analysis.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Reindexed findings for the selected rule; empty when none are reported.
     */
    private function analyseRule(string $fixture, string $ruleId): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        // dead-code.unused-private-method ships off by default (ADR-034); its tests enable it as a project would.
        $config   = (new ConfigLoader(__DIR__ . '/../../..'))->load('tests/Fixtures/Config/enable-default-off-rules.yaml', $registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter($findings, static fn($finding) => $finding->ruleId === $ruleId));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - Fixture filename under tests/Fixtures/DeadCode.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - parsed fixture carrying the repo-relative display path the rules report findings against
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/DeadCode/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/DeadCode/' . $filename));
    }
}
