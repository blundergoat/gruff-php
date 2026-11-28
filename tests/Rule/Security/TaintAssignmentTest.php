<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\HeaderInjectionRule;
use GruffPhp\Rules\Security\ProcessCommandConstructionRule;
use GruffPhp\Rules\Security\UnsafeArchiveExtractionRule;
use GruffPhp\Rules\Security\ReflectedXssRule;
use GruffPhp\Rules\Security\RequestControlledUrlRule;
use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers request-taint propagation through compound concatenation and archive receivers.
 * Archive cases distinguish tainted destinations, entries and sources from clean receiver resets.
 * Reference-alias cases expect no header findings for either a cleaned or a tainted partner.
 */
final class TaintAssignmentTest extends TestCase
{
    /**
     * Verify compound concat assignments propagate request taint into shared-helper sinks.
     *
     * @return void - Asserts one finding for each of the four selected security rules.
     */
    public function testCompoundAssignmentTaintReachesSharedHelperSinks(): void
    {
        $findings = $this->analyse('compound-assignment-taint.php');

        // One finding per tainted sink; the clean-overwrite and clean-concat functions must add none.
        self::assertRuleCount(HeaderInjectionRule::ID, 1, $findings);
        self::assertRuleCount(ReflectedXssRule::ID, 1, $findings);
        self::assertRuleCount(ProcessCommandConstructionRule::ID, 1, $findings);
        self::assertRuleCount(RequestControlledUrlRule::ID, 1, $findings);
    }

    /**
     * Verify request-controlled archive sources flag extraction while cleaned receivers stay silent.
     *
     * @return void - Asserts exact finding lines, selected messages and archive-source metadata.
     */
    public function testArchiveSourceTaintFlagsUploadedArchivesButNotCleanedReceivers(): void
    {
        $findings = array_values(array_filter(
                                     $this->analyse('archive-source-taint.php'),
                                     static fn(Finding $finding): bool => $finding->ruleId === UnsafeArchiveExtractionRule::ID,
                                 ));

        // A skippable clean reopen must not silence an extraction reached with the tainted source.



        self::assertSame(
            [18, 25, 32, 54, 66, 89, 100],
            array_map(static fn(Finding $finding): ?int => $finding->line, $findings),
        );
        self::assertSame('Archive extraction with request-controlled destination or entries detected.', $findings[0]->message);
        self::assertSame('Archive extraction of a request-controlled archive source detected.', $findings[2]->message);
        self::assertSame('archive-source', $findings[2]->metadata['taint'] ?? null);
        self::assertSame('archive-source', $findings[3]->metadata['taint'] ?? null);
        self::assertSame('archive-source', $findings[4]->metadata['taint'] ?? null);
        self::assertSame('archive-source', $findings[5]->metadata['taint'] ?? null);
        self::assertSame('archive-source', $findings[6]->metadata['taint'] ?? null);
    }

    /**
     * Verify the two reference-alias examples produce no header-injection findings.
     *
     * @return void - Asserts no header findings for aliases cleaned or tainted through their partner.
     */
    public function testReferenceAssignmentsAreDeliberatelyUntracked(): void
    {
        // Last-write-wins tracking cannot model an alias whose later writes flow both ways: it would
        // report the runtime-safe shape below and miss the runtime-unsafe one. Both stay silent.
        $unit = $this->parseSource(
            <<<'PHP'
<?php

function aliasCleanedThroughPartner(): void
{
    $source = $_GET['header'];
    $header =& $source;
    $source = 'safe';

    header($header);
}

function aliasTaintedThroughPartner(): void
{
    $source = 'safe';
    $header =& $source;
    $source = $_GET['header'];

    header($header);
}
PHP,
            'tests/Fixtures/Security/inline-reference-alias.php',
        );

        $registry = RuleRegistry::defaults();
        // header-injection is opt-in, so the test config turns it on; with it off this zero would prove nothing.
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', (new ConfigLoader(__DIR__ . '/../../..'))->load('tests/Fixtures/Config/enable-default-off-rules.yaml', $registry)));

        self::assertRuleCount(HeaderInjectionRule::ID, 0, $findings);
    }

    /**
     * Assert how many findings a rule produced within a mixed finding list.
     *
     * @param string        $ruleId - Rule id whose findings are counted.
     * @param int           $expectedCount - Expected number of findings for that rule.
     * @param list<Finding> $findings - Full-run findings to filter.
     *
     * @return void - Asserts that the selected rule has exactly the expected number of findings.
     */
    private static function assertRuleCount(string $ruleId, int $expectedCount, array $findings): void
    {
        self::assertCount(
            $expectedCount,
            array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === $ruleId)),
            sprintf('Expected %d findings for %s.', $expectedCount, $ruleId),
        );
    }

    /**
     * Run the full registry over one security fixture file, with the opt-in security rules turned on.
     *
     * @param string $fixture - Fixture filename under tests/Fixtures/Security.
     *
     * @return list<Finding> - every finding the default and opt-in rules produced for the fixture
     */
    private function analyse(string $fixture): array
    {
        $registry = RuleRegistry::defaults();
        $unit     = (new PhpFileParser())->parse(new SourceFile(
                                                     __DIR__ . '/../../Fixtures/Security/' . $fixture,
                                                     'tests/Fixtures/Security/' . $fixture,
                                                 ));

        return $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', (new ConfigLoader(__DIR__ . '/../../..'))->load('tests/Fixtures/Config/enable-default-off-rules.yaml', $registry)));
    }

    /**
     * Parse an inline PHP source string into an analysis unit with parent links.
     *
     * @param string $source - PHP source to parse.
     * @param string $displayPath - Display path stamped on any finding the unit produces.
     *
     * @return AnalysisUnit - unit carrying the parsed statements and tokens, ready for rule traversal
     */
    private function parseSource(string $source, string $displayPath): AnalysisUnit
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $statements = array_values($parser->parse($source) ?? []);
        } catch (Error $error) {
            self::fail(sprintf('Inline fixture did not parse: %s', $error->getRawMessage()));
        }

        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new ParentConnectingVisitor());
        /** @var list<Stmt> $traversed - Statements connected to parent attributes for rule traversal. */
        $traversed = $nodeTraverser->traverse($statements);

        return new AnalysisUnit(
            new SourceFile(__FILE__, $displayPath),
            $source,
            $traversed,
            array_values($parser->getTokens()),
            [],
        );
    }
}
