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
 * Covers missing PHPDoc and required parameter and return tags on methods and inherited contracts.
 * Checks finding symbols and messages, including signature details and malformed or multiline tags.
 * Covers parameter and return descriptions and their handoff to the rule for wholly bare tag blocks.
 */
final class DocsRulesTest extends DocsRuleTestCase
{
    /**
     * Verify same-file ancestors, inheritdoc markers and Override attributes supply only the contracts they declare.
     * Confirm unmatched contracts still report findings and the return-tag message asks only for the tag.
     *
     * @return void - Asserts inherited-tag exemptions, remaining findings, and the return-tag message.
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

        // Ancestor tags cover returns and parameters matched by name or position; constructor parameters and the added $audit need local tags.
        // Prose mentions, markers without ancestry, and private parent methods do not supply inherited tags.
        // Unrelated short-name matches and namespaced Override attributes are ignored; extending interfaces can inherit tags.


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
     * Verify undocumented fixture methods are reported while the documented method produces no finding.
     *
     * @return void - Asserts the exact missing-PHPDoc symbols and error severity.
     */
    public function testMissingPhpdocDetected(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPhpdocRule::ID);

        $symbols = array_map(static fn(Finding $finding): ?string => $finding->symbol, $findings);
        sort($symbols);
        self::assertSame([
            'AbstractFixture::inheritedHook()',
            'InternalHelper::complexUtility()',
            'MissingPhpdocFixture::__toString()',
            'MissingPhpdocFixture::getTitle()',
            'MissingPhpdocFixture::isActive()',
            'MissingPhpdocFixture::privateMethod()',
            'MissingPhpdocFixture::protectedMethod()',
            'MissingPhpdocFixture::setTitle()',
            'MissingPhpdocFixture::trivialUndocumented()',
            'MissingPhpdocFixture::undocumented()',
            'RuleContractFixture::analyse()',
            'RuleContractFixture::definition()',
            'TextReporter::render()',
        ], $symbols);

        $severityValues = array_values(array_unique(array_map(static fn($finding): string => $finding->severity->value, $findings)));

        self::assertSame([\GruffPhp\Results\Finding\Severity::Error->value], $severityValues);
    }

    /**
     * Verify the missing-PHPDoc message names every parameter of an undocumented private method and never calls it public.
     *
     * @return void - Asserts the parameter list and absence of public-method wording.
     */
    public function testMissingPhpdocMessageNamesEachParameter(): void
    {
        $message = $this->missingPhpdocMessages()['SignatureMessages::add()'];

        self::assertStringEndsWith('signature). Also describe parameters $a, $b and $rest.', $message);
        self::assertStringNotContainsStringIgnoringCase('public', $message);
    }

    /**
     * Verify typed and untyped value-returning methods request return descriptions.
     * Check the exact message for a void method with no parameters or throws.
     *
     * @return void - Asserts return-value hints and the complete message for the empty void method.
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
     * @return void - Asserts the direct-throw hint and its absence for the nested closure.
     */
    public function testMissingPhpdocMessageAsksForThrowsOnlyWhenTheBodyThrows(): void
    {
        $messages = $this->missingPhpdocMessages();

        self::assertStringEndsWith('signature). Also describe parameter $reason and when it throws.', $messages['SignatureMessages::fail()']);
        self::assertStringEndsWith('not a restatement of the method signature).', $messages['SignatureMessages::nestedThrow()']);
    }

    /**
     * Verify multiline array-shape @param tags are recognised when the variable follows the closing type line.
     *
     * @return void - Asserts valid multiline parameter tags are accepted and a malformed tag is reported.
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
     * Build symbol|parameter rows for findings whose metadata identifies a parameter.
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
     * Verify array parameters require explicit @param documentation.
     *
     * @return void - Asserts the undocumented array parameter is named in a finding.
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
     * Verify that the fixture's documented method needs a parameter tag even when its signature supplies the type.
     *
     * @return void - Asserts a natively typed parameter still receives a missing-tag finding.
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
     * Verify an @throws tag does not replace the scalar parameter's required @param tag.
     *
     * @return void - Asserts exception documentation does not exempt the scalar parameter from a tag.
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
     * Verify prose in a method docblock does not replace its required @param tag.
     *
     * @return void - Asserts the prose-only method receives a finding naming its parameter.
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
     * Verify a complete set of @param tags produces no missing-parameter finding.
     *
     * @return void - Asserts the fully tagged method is absent from missing-parameter findings.
     */
    public function testCompleteParamTagNotFlagged(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::complete()', $symbols);
    }

    /**
     * Verify spaces inside generic parameter types do not hide otherwise valid @param tags.
     *
     * @return void - Asserts generic parameter types containing spaces are accepted.
     */
    public function testGenericParamTagsWithSpacesAreNotFlagged(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::genericParamDocWithSpaces()', $symbols);
    }

    /**
     * Verify an array return type still requires an explicit @return tag.
     *
     * @return void - Asserts the array-returning method receives a missing-return-tag finding.
     */
    public function testMissingReturnTagDetectedForArrayReturnTypes(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingArrayReturn()', $symbols);
    }

    /**
     * Verify a native return type does not replace its required @return tag.
     *
     * @return void - Asserts a native scalar return type does not replace its required tag.
     */
    public function testMissingReturnTagDetectedWhenSignatureFullyDescribesReturn(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingReturn()', $symbols);
    }

    /**
     * Verify descriptive prose does not replace a method's required @return tag.
     *
     * @return void - Asserts the prose-only method receives a missing-return-tag finding.
     */
    public function testMissingReturnTagDetectedForDescriptiveDocblock(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingReturnForDescriptiveDocblock()', $symbols);
    }

    /**
     * Verify marker-only and private method docblocks need return tags, while a tagged private method is accepted.
     *
     * @return void - Asserts marker and private-method return findings plus the marker's parameter exemption.
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
     * Verify documented void and never methods still require an explicit return tag.
     *
     * @return void - Asserts missing-return findings for both no-value declaration types.
     */
    public function testVoidMethodWithDocblockTriggersMissingReturnTag(): void
    {
        // Policy: explicit void/never fixtures lock the broader contract requiring return tags for no-value methods.
        // See .goat-flow/learning-loop/lessons/workflow.md: "Respect explicit rule style even when it restates native syntax".



        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::voidWithDocblock()', $symbols);
        self::assertContains('PhpdocTagsFixture::neverWithDocblock()', $symbols);
    }

    /**
     * Verify the rule reports only value-returning declarations whose @return tag has no description.
     *
     * @return void - Asserts the exact bare-return symbols and advisory severity.
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
     * Verify the rule does not report described, void/never, constructor/destructor, or missing-tag cases.
     *
     * @return void - Asserts each exempt fixture symbol is absent from return-description findings.
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
     * Verify bare parameter/return blocks belong to docs.bare-phpdoc-tags.
     * Check that adding a summary leaves a bare return tag for docs.return-comment.
     *
     * @return void - Asserts the two rules report disjoint, exact fixture symbol lists.
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
     * @return void - Asserts both tag bodies are found and their description states differ.
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
     * @return void - Asserts the exact bare-return findings and absence of complete, described, or void cases.
     */
    public function testReturnDescriptionFiresOnRealisticBareReturnTags(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', ReturnCommentRule::ID);
        $symbols  = array_map(static fn($finding): ?string => $finding->symbol, $findings);
        sort($symbols);

        // Wholly bare tag blocks belong to docs.bare-phpdoc-tags, so this rule must not report arrayShapeDoc() or uselessDoc().

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
     * Verify docs.param-comment names all bare parameters in one finding per declaration.
     * Check callable and multiline array-shape tags without reporting described parameters.
     *
     * @return void - Asserts one message per affected declaration, naming only its bare parameters.
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
     * Verify a bare tags-only function is omitted by docs.param-comment and reported by docs.bare-phpdoc-tags.
     *
     * @return void - Asserts tagsOnly() is absent from parameter-description findings and is the sole bare-tag finding.
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
     * Verify parameter descriptions are checked by default and an explicit rule disablement remains effective.
     *
     * @return void - Asserts three findings by default and none when the rule is disabled.
     */
    public function testParamCommentIsOnByDefaultAndCanBeDisabled(): void
    {
        self::assertCount(3, $this->analyseSourceRule($this->paramCommentSource(), ParamCommentRule::ID));
        $config   = AnalysisConfig::fromRegistry(RuleRegistry::defaults());
        $settings = $config->ruleSettings(ParamCommentRule::ID);
        $config   = $config->withRuleSettings(ParamCommentRule::ID, new RuleSettings(false, $settings->thresholds, $settings->options));
        self::assertSame([], $this->analyseSourceRule($this->paramCommentSource(), ParamCommentRule::ID, $config));
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
     * Returns inline source covering bare, described, callable-typed, array-shaped and tags-only @param tags.
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


        foreach ($this->analyseSourceRule($source, MissingPhpdocRule::ID) as $finding) {
            $messages[(string) $finding->symbol] = $finding->message;
        }

        return $messages;
    }
}
