<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Rules\Docs\BarePhpdocTagsRule;
use GruffPhp\Rules\Docs\MissingParamTagRule;
use GruffPhp\Rules\Docs\MissingPhpdocRule;
use GruffPhp\Rules\Docs\MissingReturnTagRule;
use GruffPhp\Rules\Docs\ParamCommentRule;
use GruffPhp\Rules\Docs\PhpdocTagText;
use GruffPhp\Rules\Docs\ReturnCommentRule;
use GruffPhp\Rules\RuleRegistry;

/**
 * Covers documentation rule enforcement: missing PHPDoc on public/accessor/private/magic/interface-contract methods, and missing param and return
 * tags across array, descriptive, void, and scalar-throws cases.
 */
final class DocsRulesTest extends DocsRuleTestCase
{
    /**
     * Verify an inherited contract satisfies the return and param tags only for what it really declares, whether a
     * same-file ancestor documents it or `{@inheritdoc}` or `#[Override]` points elsewhere, and that the return-tag
     * message asks only for the tag.
     *
     * @return void
     */
    public function testInheritedContractsSatisfyReturnAndParamTags(): void
    {
        $returnFindings = [
            ...$this->analyseRule('inherited-contract-tags.php', MissingReturnTagRule::ID),
            ...$this->analyseRule('inherited-contract-namespaces.php', MissingReturnTagRule::ID),
        ];
        $paramFindings = [
            ...$this->analyseRule('inherited-contract-tags.php', MissingParamTagRule::ID),
            ...$this->analyseRule('inherited-contract-namespaces.php', MissingParamTagRule::ID),
        ];
        $returnSymbols = array_map(static fn(Finding $finding): string => (string) $finding->symbol, $returnFindings);
        $paramSymbols  = array_map(
            static fn(Finding $finding): string => $finding->symbol . ' $' . (is_string($finding->metadata['parameter'] ?? null) ? $finding->metadata['parameter'] : ''),
            $paramFindings,
        );
        sort($returnSymbols);
        sort($paramSymbols);

        // Only what a visible or marked contract really documents is exempt: find(), count(), exists(), purge() and
        // AuditedRepository::save() stay silent, while a constructor, a parameter the override adds, a parent with
        // prose only, a prose mention of @inheritdoc, a marker with nothing to inherit, a same-named class in another
        // namespace, a namespaced Override attribute, a method whose parent's run() is private, and a parameter a
        // parent never documents under that name all still owe their tags; an extending interface's marker holds.
        self::assertSame([
            'AuditedRepository::rename()',
            'HalfDocumentedPair::pair()',
            'Job::run()',
            'Job::stop()',
            'ProseOnlyChild::remove()',
            'ProseOnlyRepository::remove()',
            'PublicRunner::run()',
            'StandaloneRepository::lookup()',
            'StandaloneRepository::tally()',
            'SwappedPair::pair()',
        ], $returnSymbols);
        self::assertSame([
            'AuditedRepository::rename() $name',
            'AuditedRepository::rename() $recordId',
            'HalfDocumentedPair::pair() $right',
            'Job::run() $limit',
            'Job::stop() $code',
            'PromotingService::__construct() $retryLimit',
            'PromotingService::__construct() $serviceName',
            'ProseOnlyChild::remove() $recordId',
            'ProseOnlyRepository::remove() $recordId',
            'PublicRunner::run() $limit',
            'StandaloneRepository::lookup() $recordId',
            'StandaloneRepository::tally() $limit',
            'SwappedPair::pair() $right',
            'WideningRepository::save() $audit',
        ], $paramSymbols);
        self::assertSame(
            'AuditedRepository::rename() has a docblock but no @return tag declaring its return contract.',
            $returnFindings[0]->message,
        );
    }

    /**
     * Verify missing public phpdoc detected.
     *
     * @return void
     */
    public function testMissingPhpdocDetected(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPhpdocRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::undocumented()', $symbols);
        self::assertContains('MissingPhpdocFixture::trivialUndocumented()', $symbols);
        self::assertContains('MissingPhpdocFixture::privateMethod()', $symbols);
        self::assertContains('MissingPhpdocFixture::protectedMethod()', $symbols);
        self::assertContains('MissingPhpdocFixture::__toString()', $symbols);
        self::assertContains('AbstractFixture::inheritedHook()', $symbols);

        $severityValues = array_values(array_unique(array_map(static fn($finding): string => $finding->severity->value, $findings)));

        self::assertSame([\GruffPhp\Results\Finding\Severity::Error->value], $severityValues);
    }

    /**
     * Verify documented method not flagged.
     *
     * @return void
     */
    public function testDocumentedMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPhpdocRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('MissingPhpdocFixture::documented()', $symbols);
    }

    /**
     * Verify the missing-PHPDoc message names every parameter of an undocumented private method and never calls it public.
     *
     * @return void
     */
    public function testMissingPhpdocMessageNamesEachParameter(): void
    {
        $message = $this->missingPhpdocMessages()['SignatureMessages::add()'];

        self::assertStringEndsWith('signature). Also describe parameters $a, $b and $rest.', $message);
        self::assertStringNotContainsStringIgnoringCase('public', $message);
    }

    /**
     * Verify the message asks for the return value only when the declaration yields one, and that a method with no
     * parameters, no value and no throw keeps the exact message it had before this change.
     *
     * @return void
     */
    public function testMissingPhpdocMessageAsksForReturnValueOnlyWhenOneExists(): void
    {
        $messages = $this->missingPhpdocMessages();

        self::assertStringEndsWith('signature). Also describe the return value.', $messages['SignatureMessages::value()']);
        self::assertStringEndsWith('signature). Also describe the return value.', $messages['SignatureMessages::untyped()']);
        self::assertSame(
            'Method SignatureMessages::nothing() needs a brief intent description above its declaration (one plain-English line; not a restatement of the method signature).',
            $messages['SignatureMessages::nothing()'],
        );
    }

    /**
     * Verify the message asks when the callable throws only for a throw in its own body, not one inside a nested closure.
     *
     * @return void
     */
    public function testMissingPhpdocMessageAsksForThrowsOnlyWhenTheBodyThrows(): void
    {
        $messages = $this->missingPhpdocMessages();

        self::assertStringEndsWith('signature). Also describe parameter $reason and when it throws.', $messages['SignatureMessages::fail()']);
        self::assertStringEndsWith('not a restatement of the method signature).', $messages['SignatureMessages::nestedThrow()']);
    }

    /**
     * Verify accessors require phpdoc.
     *
     * @return void
     */
    public function testAccessorsRequirePhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPhpdocRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::getTitle()', $symbols);
        self::assertContains('MissingPhpdocFixture::setTitle()', $symbols);
        self::assertContains('MissingPhpdocFixture::isActive()', $symbols);
    }

    /**
     * Verify private and protected methods require phpdoc.
     *
     * @return void
     */
    public function testPrivateAndProtectedMethodsRequirePhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPhpdocRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::privateMethod()', $symbols);
        self::assertContains('MissingPhpdocFixture::protectedMethod()', $symbols);
    }

    /**
     * Verify trivial typed public method requires phpdoc.
     *
     * @return void
     */
    public function testTrivialTypedPublicMethodRequiresPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPhpdocRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::trivialUndocumented()', $symbols);
    }

    /**
     * Verify magic method requires phpdoc.
     *
     * @return void
     */
    public function testMagicMethodRequiresPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPhpdocRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::__toString()', $symbols);
    }

    /**
     * Verify rule interface contract methods require local phpdoc.
     *
     * @return void
     */
    public function testRuleInterfaceContractMethodsRequireLocalPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPhpdocRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('RuleContractFixture::definition()', $symbols);
        self::assertContains('RuleContractFixture::analyse()', $symbols);
    }

    /**
     * Verify internal helpers and conventional reporters require phpdoc.
     *
     * @return void
     */
    public function testInternalHelpersAndConventionalReportersRequirePhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPhpdocRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('InternalHelper::complexUtility()', $symbols);
        self::assertContains('TextReporter::render()', $symbols);
    }

    /**
     * Verify missing param tag recognises multi-line `@param array{...}` shapes whose closing documented
     * variable sits on a different physical line than the `@param` token.
     *
     * @return void
     */
    public function testMissingParamTagRecognisesMultiLineArrayShape(): void
    {
        $findings = $this->analyseRule('missing-param-tag-multi-line-array-shape.php', MissingParamTagRule::ID);

        $reported = $this->reportedParametersBySymbol($findings);

        self::assertNotContains('MultiLineArrayShapeFixture::publishToolUse()|topic', $reported);
        self::assertNotContains('MultiLineArrayShapeFixture::publishToolUse()|payload', $reported);
        self::assertContains('MultiLineArrayShapeFixture::publishTurnWithMalformedDoc()|payload', $reported);
    }

    /**
     * Build `symbol|parameter` rows for findings whose metadata identifies a parameter.
     *
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Findings from a missing-param-tag fixture.
     *
     * @return list<string> - symbol and parameter pairs in finding order; entries without string parameter metadata are omitted
     */
    private function reportedParametersBySymbol(array $findings): array
    {
        $reported = [];

        foreach ($findings as $finding) {
            $symbol    = $finding->symbol ?? '';
            $parameter = $finding->metadata['parameter'] ?? null;
            if (is_string($parameter)) {
                $reported[] = sprintf('%s|%s', $symbol, $parameter);
            }
        }

        return $reported;
    }

    /**
     * Verify missing param tag detected for array parameters.
     *
     * @return void
     */
    public function testMissingParamTagDetectedForArrayParameters(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $arrayParamFindings = array_filter(
            $findings,
            static fn($finding): bool => ($finding->symbol ?? '') === 'PhpdocTagsFixture::missingArrayParam()',
        );

        $params = array_map(static fn($finding) => $finding->metadata['parameter'] ?? null, $arrayParamFindings);
        self::assertContains('y', $params);
    }

    /**
     * Verify missing param tag detected for documented methods even when signature type describes
     * parameter.
     *
     * @return void
     */
    public function testMissingParamTagDetectedForDocumentedMethodsEvenWhenSignatureTypeDescribesParameter(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $scalarParamFindings = array_filter(
            $findings,
            static fn($finding): bool => ($finding->symbol ?? '') === 'PhpdocTagsFixture::missingParam()',
        );

        $params = array_map(static fn($finding) => $finding->metadata['parameter'] ?? null, $scalarParamFindings);
        self::assertContains('y', $params);
    }

    /**
     * Verify missing param tag detected when throws doc omits scalar param.
     *
     * @return void
     */
    public function testMissingParamTagDetectedWhenThrowsDocOmitsScalarParam(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $scalarParamFindings = array_filter(
            $findings,
            static fn($finding): bool => ($finding->symbol ?? '') === 'PhpdocTagsFixture::missingScalarParamWithThrows()',
        );

        $params = array_map(static fn($finding) => $finding->metadata['parameter'] ?? null, $scalarParamFindings);
        self::assertContains('groupId', $params);
    }

    /**
     * Verify missing param tag detected for descriptive docblock.
     *
     * @return void
     */
    public function testMissingParamTagDetectedForDescriptiveDocblock(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $descriptiveDocFindings = array_filter(
            $findings,
            static fn($finding): bool => ($finding->symbol ?? '') === 'PhpdocTagsFixture::missingReturnForDescriptiveDocblock()',
        );

        $params = array_map(static fn($finding) => $finding->metadata['parameter'] ?? null, $descriptiveDocFindings);
        self::assertContains('groupId', $params);
    }

    /**
     * Verify complete param tag not flagged.
     *
     * @return void
     */
    public function testCompleteParamTagNotFlagged(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::complete()', $symbols);
    }

    /**
     * Verify generic param tags with spaces are not flagged.
     *
     * @return void
     */
    public function testGenericParamTagsWithSpacesAreNotFlagged(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::genericParamDocWithSpaces()', $symbols);
    }

    /**
     * Verify missing return tag detected for array return types.
     *
     * @return void
     */
    public function testMissingReturnTagDetectedForArrayReturnTypes(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingArrayReturn()', $symbols);
    }

    /**
     * Verify missing return tag detected when signature fully describes return.
     *
     * @return void
     */
    public function testMissingReturnTagDetectedWhenSignatureFullyDescribesReturn(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingReturn()', $symbols);
    }

    /**
     * Verify missing return tag detected for descriptive docblock.
     *
     * @return void
     */
    public function testMissingReturnTagDetectedForDescriptiveDocblock(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingReturnForDescriptiveDocblock()', $symbols);
    }

    /**
     * Verify any documented method requires return tag.
     *
     * @return void
     */
    public function testAnyDocumentedMethodRequiresReturnTag(): void
    {
        $paramFindings  = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);
        $returnFindings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $paramSymbols  = array_map(static fn($finding) => $finding->symbol, $paramFindings);
        $returnSymbols = array_map(static fn($finding) => $finding->symbol, $returnFindings);

        self::assertNotContains('PhpdocTagsFixture::apiMarkerOnly()', $paramSymbols);
        self::assertContains('PhpdocTagsFixture::apiMarkerOnly()', $returnSymbols);
        self::assertContains('PhpdocTagsFixture::privateMissingReturnTag()', $returnSymbols);
        self::assertNotContains('PhpdocTagsFixture::privateCompleteReturnTag()', $returnSymbols);
    }

    /**
     * Verify void method with docblock triggers missing return tag.
     *
     * @return void
     */
    public function testVoidMethodWithDocblockTriggersMissingReturnTag(): void
    {
        // Policy lock: per .goat-flow/learning-loop/lessons/workflow.md "Respect explicit rule style
        // even when it restates native syntax", every documented method without @return
        // must fire - including methods declared void or never. Skipping void was an
        // unintended narrowing; explicit void/never fixtures lock the broader contract
        // so a future agent cannot silently re-narrow it.
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::voidWithDocblock()', $symbols);
        self::assertContains('PhpdocTagsFixture::neverWithDocblock()', $symbols);
    }

    /**
     * Verify the rule fires only on value-returning declarations whose @return tag has no description.
     *
     * @return void
     */
    public function testReturnDescriptionFiresOnlyOnBareValueReturnTags(): void
    {
        $findings = $this->analyseRule('return-description.php', ReturnCommentRule::ID);
        $symbols  = array_map(static fn($finding): ?string => $finding->symbol, $findings);
        sort($symbols);

        self::assertSame([
                             'ReturnDescriptionFixture::bareGenericReturnFires()',
                             'ReturnDescriptionFixture::bareReturnFires()',
                             'ReturnDescriptionFixture::untypedBareReturnFires()',
                         ], $symbols);

        foreach ($findings as $finding) {
            self::assertSame(Severity::Advisory, $finding->severity, 'described-return-tag findings are advisory');
        }
    }

    /**
     * Verify the rule stays silent on described, void/never, constructor/destructor, and missing-tag cases.
     *
     * @return void
     */
    public function testReturnDescriptionStaysSilentOutsideItsContract(): void
    {
        $findings = $this->analyseRule('return-description.php', ReturnCommentRule::ID);
        $symbols  = array_map(static fn($finding): ?string => $finding->symbol, $findings);

        foreach ([
                     'ReturnDescriptionFixture::describedReturnIsClean()',
                     'ReturnDescriptionFixture::describedGenericReturnIsClean()',
                     'ReturnDescriptionFixture::voidIsClean()',
                     'ReturnDescriptionFixture::neverIsClean()',
                     'ReturnDescriptionFixture::__construct()',
                     'ReturnDescriptionFixture::__destruct()',
                     'ReturnDescriptionFixture::noReturnTagIsClean()',
                     'ReturnDescriptionFixture::untypedVoidBodyIsClean()',
                 ] as $silentSymbol) {
            self::assertNotContains($silentSymbol, $symbols, $silentSymbol . ' must not be flagged by docs.return-comment');
        }
    }

    /**
     * Verify a wholly tags-only docblock is reported once, by docs.bare-phpdoc-tags, while a summary over a bare
     * `@return` still reports under docs.return-comment (M13-T23).
     *
     * @return void
     */
    public function testReturnDescriptionLeavesTagsOnlyBlocksToBarePhpdocTags(): void
    {
        $source = <<<'PHP'
<?php

/**
 * @param int $value
 *
 * @return int
 */
function tagsOnly(int $value): int
{
    return $value;
}

/**
 * Doubles the value.
 *
 * @param int $value - number to double
 *
 * @return int
 */
function summaryWithBareReturn(int $value): int
{
    return $value * 2;
}

final class TagsOnlyHolder
{
    /**
     * @param int $value
     *
     * @return int
     */
    public function tagsOnlyMethod(int $value): int
    {
        return $value;
    }
}
PHP;
        $returnSymbols = array_map(static fn(Finding $finding): ?string => $finding->symbol, $this->analyseSourceRule($source, ReturnCommentRule::ID));
        $bareSymbols   = array_map(static fn(Finding $finding): ?string => $finding->symbol, $this->analyseSourceRule($source, BarePhpdocTagsRule::ID));
        sort($bareSymbols);

        self::assertSame(['summaryWithBareReturn()'], $returnSymbols);
        self::assertSame(['TagsOnlyHolder::tagsOnlyMethod()', 'tagsOnly()'], $bareSymbols);
    }

    /**
     * Verify multiline array-shape return tags are scanned through their closing type line.
     *
     * @return void
     */
    public function testReturnTagTextReadsMultilineReturnDescriptions(): void
    {
        $described = <<<'PHPDOC'
/**
 * Build payload.
 *
 * @return array{
 *     id: string,
 *     rows: list<string>
 * } - payload keyed by id with rows in display order
 */
PHPDOC;
        $bare = <<<'PHPDOC'
/**
 * Build payload.
 *
 * @return array{
 *     id: string,
 *     rows: list<string>
 * }
 */
PHPDOC;

        $describedBody = PhpdocTagText::returnTagBody($described);
        $bareBody      = PhpdocTagText::returnTagBody($bare);

        self::assertNotNull($describedBody);
        self::assertNotNull($bareBody);
        self::assertTrue(PhpdocTagText::hasReturnTagDescription($describedBody));
        self::assertFalse(PhpdocTagText::hasReturnTagDescription($bareBody));
    }

    /**
     * Verify the described-return contract holds on the shared, realistic phpdoc-tags fixture.
     *
     * @return void
     */
    public function testReturnDescriptionFiresOnRealisticBareReturnTags(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', ReturnCommentRule::ID);
        $symbols  = array_map(static fn($finding): ?string => $finding->symbol, $findings);
        sort($symbols);

        // M13-T23: arrayShapeDoc() and uselessDoc() are wholly tags-only docblocks, which docs.bare-phpdoc-tags
        // reports, so docs.return-comment no longer reports them a second time.
        self::assertSame([
                             'PhpdocTagsFixture::missingArrayParam()',
                             'PhpdocTagsFixture::missingParam()',
                             'PhpdocTagsFixture::staleParam()',
                         ], $symbols);

        self::assertNotContains('PhpdocTagsFixture::complete()', $symbols);
        self::assertNotContains('PhpdocTagsFixture::describedReturnTagDoc()', $symbols);
        self::assertNotContains('PhpdocTagsFixture::voidWithDocblock()', $symbols);
    }

    /**
     * Verify docs.param-comment, once enabled, reports one finding per declaration naming every bare `@param`, reads
     * past callable and multiline array-shape types, and leaves described tags alone (M13-T24).
     *
     * @return void
     */
    public function testParamCommentNamesEveryBareParamOncePerDeclaration(): void
    {
        $messages = array_map(
            static fn(Finding $finding): string => $finding->message,
            $this->analyseSourceRule($this->paramCommentSource(), ParamCommentRule::ID, $this->paramCommentEnabled()),
        );
        sort($messages);

        self::assertSame([
                             'BareParamHolder::store() has a bare @param tag for $value; describe what the parameter means, not just its type.',
                             'bareCallableParam() has a bare @param tag for $callback; describe what the parameter means, not just its type.',
                             'twoBareParams() has bare @param tags for $alpha, $gamma; describe what each parameter means, not just its type.',
                         ], $messages);
    }

    /**
     * Verify docs.param-comment shares the tags-only predicate, so a wholly tags-only docblock is reported once, by
     * docs.bare-phpdoc-tags (M13-T24).
     *
     * @return void
     */
    public function testParamCommentLeavesTagsOnlyBlocksToBarePhpdocTags(): void
    {
        $paramSymbols = array_map(
            static fn(Finding $finding): ?string => $finding->symbol,
            $this->analyseSourceRule($this->paramCommentSource(), ParamCommentRule::ID, $this->paramCommentEnabled()),
        );
        $bareSymbols = array_map(
            static fn(Finding $finding): ?string => $finding->symbol,
            $this->analyseSourceRule($this->paramCommentSource(), BarePhpdocTagsRule::ID, $this->paramCommentEnabled()),
        );

        self::assertNotContains('tagsOnly()', $paramSymbols);
        self::assertSame(['tagsOnly()'], $bareSymbols);
    }

    /**
     * Verify docs.param-comment is off by default, so default settings report nothing new (M13-T24).
     *
     * @return void
     */
    public function testParamCommentIsOffByDefault(): void
    {
        self::assertSame([], $this->analyseSourceRule($this->paramCommentSource(), ParamCommentRule::ID));
    }

    /**
     * Builds a config with docs.param-comment switched on and every other rule at its default.
     *
     * @return AnalysisConfig - registry defaults plus the enabled docs.param-comment rule
     */
    private function paramCommentEnabled(): AnalysisConfig
    {
        $config = AnalysisConfig::fromRegistry(RuleRegistry::defaults());

        return $config->withRuleSettings(
            ParamCommentRule::ID,
            new RuleSettings(true, $config->ruleSettings(ParamCommentRule::ID)->thresholds),
        );
    }

    /**
     * Returns inline source covering bare, described, callable-typed, array-shaped and tags-only `@param` tags.
     *
     * @return string - PHP source for the docs.param-comment tests
     */
    private function paramCommentSource(): string
    {
        return <<<'PHP'
<?php

/**
 * Sums three values.
 *
 * @param int $alpha
 * @param int $beta - second addend
 * @param $gamma
 *
 * @return int - the sum
 */
function twoBareParams(int $alpha, int $beta, $gamma): int
{
    return $alpha + $beta + $gamma;
}

/**
 * Applies a callback to every row.
 *
 * @param callable(int $row): void $callback
 * @param array{
 *     id: int,
 *     name: string,
 * } $rows - rows to walk
 *
 * @return void
 */
function bareCallableParam(callable $callback, array $rows): void
{
}

/**
 * @param int $value
 *
 * @return int
 */
function tagsOnly(int $value): int
{
    return $value;
}

/**
 * Doubles the value.
 *
 * @param int $value - number to double
 *
 * @return int - the doubled number
 */
function describedParam(int $value): int
{
    return $value * 2;
}

final class BareParamHolder
{
    /**
     * Stores the value.
     *
     * @param int $value
     *
     * @return void
     */
    private function store(int $value): void
    {
    }
}
PHP;
    }

    /**
     * Runs docs.missing-phpdoc over one class of undocumented methods that differ only in signature and body.
     *
     * @return array<string, string> - Finding message keyed by symbol.
     */
    private function missingPhpdocMessages(): array
    {
        $source = <<<'PHP'
            <?php

            final class SignatureMessages
            {
                private function add(int $a, int &$b, int ...$rest): void
                {
                }

                public function value(): int
                {
                    return 1;
                }

                public function untyped()
                {
                    return 2;
                }

                public function nothing(): void
                {
                }

                public function fail(string $reason): never
                {
                    throw new \RuntimeException($reason);
                }

                public function nestedThrow(): void
                {
                    $callback = static function (): void {
                        throw new \RuntimeException('later');
                    };
                }
            }
            PHP;

        $messages = [];

        // Key each message by its method symbol so a test reads the one it pins.
        foreach ($this->analyseSourceRule($source, MissingPhpdocRule::ID) as $finding) {
            $messages[(string) $finding->symbol] = $finding->message;
        }

        return $messages;
    }
}
