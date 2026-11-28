<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\TestQuality\RepeatedStructureMissingDataProviderRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers repeated-structure findings in legacy and complete-syntax grouping modes.
 * Fixture cases check traversal past ineligible methods and declaration-sensitive grouping.
 * Assertions check reported symbols, selected metadata, messages and declaration anchors.
 */
final class RepeatedStructureMissingDataProviderRuleTest extends TestCase
{
    /** Rule instance under test. */
    private RepeatedStructureMissingDataProviderRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Create a fresh rule and parser before each test.
     *
     * @return void - Initializes the rule and parser used by the fixture helpers.
     */
    protected function setUp(): void
    {
        $this->rule   = new RepeatedStructureMissingDataProviderRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify repeated structures are found after non-candidate methods.
     *
     * @return void - Asserts the five expected finding symbols in emitted order.
     */
    public function testRepeatedStructuresAreFoundAfterNonCandidates(): void
    {
        $findings = $this->analyse('repeated-structure-mutation-cases.php');
        $symbols  = array_map(static fn(Finding $finding): ?string => $finding->symbol, $findings);

        self::assertSame([
                             'ContinuePastNonTestMethodTest::testAlpha()',
                             'ContinuePastShortMethodTest::testAlpha()',
                             'ContinuePastProviderTest::testShapeAlpha()',
                             'ContinuePastSmallGroupTest::testRealAlpha()',
                             'NonProviderAttributeStillAnalysedTest::testDecoratedAlpha()',
                         ], $symbols);
    }

    /**
     * Verify selected findings carry grouped method metadata and message.
     *
     * @return void - Asserts five findings, selected member metadata and the fourth finding's message.
     */
    public function testFindingsCarryGroupedMethodMetadata(): void
    {
        $findings = $this->analyse('repeated-structure-mutation-cases.php');

        self::assertCount(5, $findings);
        self::assertSame(3, $findings[0]->metadata['count'] ?? null);
        self::assertSame(['testAlpha', 'testBeta', 'testGamma'], $findings[0]->metadata['methods'] ?? null);
        self::assertSame(3, $findings[3]->metadata['count'] ?? null);
        self::assertSame(['testRealAlpha', 'testRealBeta', 'testRealGamma'], $findings[3]->metadata['methods'] ?? null);
        self::assertStringContainsString(
            'ContinuePastSmallGroupTest has 3 structurally identical test methods',
            $findings[3]->message,
        );
    }

    /**
     * Verifies group mode reports the eligible fixture classes and omits the distinct controls.
     *
     * @return void - Asserts exactly the seven expected group symbols.
     */
    public function testGroupModeKeepsOnlyEligibleClasses(): void
    {
        $findings = $this->analyse('repeated-structure-groups.php', true);

        self::assertSame([
            'LiteralGroupTest::testAlpha()',
            'MatchingAttributesTest::testAlpha()',
            'MatchingAnnotationsTest::testAlpha()',
            'MatchingPayloadTest::testAlpha()',
            'MatchingArrayKeysTest::testAlpha()',
            'ReassignedIterableTest::testAlpha()',
            'AnnotationArgumentTest::testAlpha()',
        ], array_map(static fn (Finding $finding): ?string => $finding->symbol, $findings));
    }

    /**
     * Verifies one finding lists every member of a larger group and asks for labelled provider rows.
     *
     * @return void - Asserts four member lines, the anchor, label guidance and no ignoredPathPatterns mention in remediation.
     */
    public function testGroupModeListsEveryMemberAndItsDeclarationLine(): void
    {
        $finding = $this->analyse('repeated-structure-groups.php', null)[0];

        self::assertSame(4, $finding->metadata['groupSize']);
        self::assertSame([
            'LiteralGroupTest::testAlpha()' => 14,
            'LiteralGroupTest::testBeta()' => 15,
            'LiteralGroupTest::testGamma()' => 16,
            'LiteralGroupTest::testDelta()' => 17,
        ], $finding->metadata['members']);
        self::assertSame(14, $finding->line);
        self::assertStringContainsString('LiteralGroupTest::testDelta() (line 17)', $finding->message);
        self::assertStringContainsString('descriptive label per case', $finding->message);
        self::assertStringNotContainsString('ignoredPathPatterns', $finding->remediation ?? '');
    }

    /**
     * Verifies attribute lines do not replace the method declaration as the finding anchor.
     *
     * @return void - Asserts the finding and first member use declaration line 23.
     */
    public function testGroupModeAnchorsAfterAttributes(): void
    {
        $finding = $this->analyse('repeated-structure-groups.php', true)[1];

        $members = $finding->metadata['members'];
        self::assertIsArray($members);
        self::assertSame(23, $finding->line);
        self::assertSame(23, $members['MatchingAttributesTest::testAlpha()']);
    }

    /**
     * Verifies the group option keeps data-provider exclusions and traversal past non-candidates.
     *
     * @return void - Asserts the five expected fixture symbols in group mode.
     */
    public function testGroupModePreservesExistingProviderControls(): void
    {
        $findings = $this->analyse('repeated-structure-mutation-cases.php', true);

        self::assertSame([
            'ContinuePastNonTestMethodTest::testAlpha()',
            'ContinuePastShortMethodTest::testAlpha()',
            'ContinuePastProviderTest::testShapeAlpha()',
            'ContinuePastSmallGroupTest::testRealAlpha()',
            'NonProviderAttributeStillAnalysedTest::testDecoratedAlpha()',
        ], array_map(static fn (Finding $finding): ?string => $finding->symbol, $findings));
    }

    /**
     * Verify grouping rejects differing declarations and accepts the eligible control fixtures.
     *
     * @param string $className - Fixture class whose group is checked.
     * @param bool   $expected  - Whether the class should report one complete group.
     * @return void - Asserts one testAlpha finding for accepted fixtures and none for rejected fixtures.
     */
    #[DataProvider('declarationCases')]
    public function testGroupModeKeepsDeclarationsExact(string $className, bool $expected): void
    {
        $findings = $this->analyse('repeated-structure-declarations.php', true);
        $symbols  = array_values(array_filter(
            array_map(static fn (Finding $finding): ?string => $finding->symbol, $findings),
            static fn (?string $symbol): bool => str_starts_with($symbol ?? '', $className . '::'),
        ));

        self::assertSame($expected ? [$className . '::testAlpha()'] : [], $symbols);
    }

    /**
     * Lists declaration and closure fixtures with their expected grouping outcomes.
     *
     * @return array<string, array{string, bool}> - Named fixture classes and expected grouping.
     */
    public static function declarationCases(): array
    {
        return [
            'named function' => ['NestedFunctionTest', false],
            'function in block' => ['NestedFunctionInBlockTest', false],
            'anonymous class' => ['AnonymousClassTest', false],
            'class in closure' => ['NestedClassInClosureTest', false],
            'declaration documentation' => ['DeclarationDocumentationTest', false],
            'body attribute' => ['BodyAttributeTest', false],
            'closure values' => ['ClosureValuesTest', true],
            'identical declaration' => ['IdenticalDeclarationTest', true],
        ];
    }

    /**
     * Parse and analyse a repeated-structure fixture.
     *
     * @param string $fixture - Filename under tests/Fixtures/TestQuality/.
     * @param bool|null $groupFindings - Explicit mode, or null to use the configured default.
     *
     * @return list<Finding> - rule findings for the fixture, ordered as the rule emits them, empty when none match
     */
    private function analyse(string $fixture, ?bool $groupFindings = false): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        if ($groupFindings !== null) {
            $settings = $config->ruleSettings(RepeatedStructureMissingDataProviderRule::ID);
            $config   = $config->withRuleSettings(RepeatedStructureMissingDataProviderRule::ID, new RuleSettings(
                enabled:           $settings->enabled,
                thresholds:        $settings->thresholds,
                options:           array_replace($settings->options, ['groupFindings' => $groupFindings]),
                severityThreshold: $settings->severityThreshold,
                excludeFromScore:  $settings->excludeFromScore,
            ));
        }
        $ruleContext = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $ruleContext);
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - Filename under tests/Fixtures/TestQuality/.
     *
     * @return AnalysisUnit - Fixture contents and any read or parse diagnostics, with a repository-relative display path.
     */
    private function parseFixture(string $filename): AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/TestQuality/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/TestQuality/' . $filename));
    }
}
