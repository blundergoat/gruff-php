<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Baseline;

use GruffPhp\Results\Baseline\BaselineData;
use GruffPhp\Results\Baseline\BaselineEntry;
use GruffPhp\Results\Baseline\BaselineException;
use GruffPhp\Results\Baseline\BaselineFilter;
use GruffPhp\Results\Finding\BaselineIdentity;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\DeclarationInventory;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Covers reviewed baseline counts, line movement, and symbol and file identity changes.
 * Exercises variable ordinals, message normalisation, sensitive findings, and diff-scoped reports.
 * Checks foreign-port refusal and retention of findings whose symbols contain the ordinal separator.
 */
final class BaselineFilterTest extends TestCase
{
    /**
     * Verify the fixed digests for the supplied language, path, and subject combinations.
     *
     * @return void - Asserts the expected digest for each supplied vector.
     */
    public function testIdentityMatchesTheFamilyOracle(): void
    {
        self::assertSame('aff839f0cf33b11e', BaselineIdentity::computeFor('rs', 'docs.missing-readme', 'src/widget.rs', 'process#1'));
        self::assertSame('4ab8dc0e1ec4b969', BaselineIdentity::computeFor('rs', 'docs.missing-readme', 'src/widget.rs', 'process#2'));
        self::assertSame('bdb4503a37614a4f', BaselineIdentity::computeFor('rs', 'docs.missing-readme', 'src/widget.rs', 'File has no module documentation'));
        self::assertSame('caa4bb2431af313d', BaselineIdentity::computeFor('ts', 'docs.missing-readme', 'src/widget.rs', 'process#1'));
        self::assertSame('8f717ea2d0f8af15', BaselineIdentity::computeFor('rs', 'docs.missing-readme', 'src/gadget.rs', 'process#1'));
        self::assertSame('0b6106408093761c', BaselineIdentity::computeFor('rs', 'docs.missing-readme', 'src/widget.rs', 'File has # lines (limit #)'));
    }

    /**
     * Verify numeric groups are normalised in messages while text without numbers stays unchanged.
     *
     * @return void - Asserts replacements for integer, decimal, grouped-number, and version examples and preserves number-free text.
     */
    public function testMeasuredValuesNeverEnterASymbolLessIdentity(): void
    {
        self::assertSame('File has # lines (limit #)', BaselineIdentity::normaliseMeasuredValues('File has 1010 lines (limit 1000)'));
        self::assertSame('#% over # lines in v#', BaselineIdentity::normaliseMeasuredValues('12.5% over 1,234 lines in v0.5.2'));
        self::assertSame('File has no module documentation', BaselineIdentity::normaliseMeasuredValues('File has no module documentation'));
    }

    /**
     * Verify that only the reviewed ordinary finding is removed from the retained findings in this scenario.
     *
     * @return void - Asserts the retained fresh and sensitive findings and the unchanged, new, and ineligible counts.
     */
    public function testABaselineOnlyEverRemovesReviewedFindings(): void
    {
        $reviewed = $this->finding();
        $fresh    = $this->finding(line: 400, symbol: 'App::other()');
        $secret   = $this->finding(ruleId: 'sensitive-data.aws-access-key', message: 'Possible AWS key.', symbol: null, pillar: Pillar::SensitiveData);

        $application = (new BaselineFilter())->apply($this->baseline([$reviewed, $secret]), [$reviewed, $fresh, $secret], false);


        self::assertSame([$fresh, $secret], $application['findings']);
        self::assertSame(1, $application['report']->unchangedCount);
        self::assertSame(1, $application['report']->newCount);
        self::assertSame(1, $application['report']->notEligibleCount);
    }

    /**
     * Verify a line-shifted finding still matches its reviewed identity.
     *
     * @return void - Asserts one unchanged finding, no new findings, and no absent occurrences after the line shift.
     */
    public function testLineShiftedFindingStaysUnchanged(): void
    {
        $application = $this->classify([$this->finding(line: 10)], [$this->finding(line: 310)]);

        self::assertSame([], $application['new']);
        self::assertCount(1, $application['unchanged']);
        self::assertSame(0, $application['report']->absentCount);
    }

    /**
     * Verify an empty baseline reports both supplied ordinary findings as new.
     *
     * @return void - Asserts two new findings, no unchanged findings, and no absent occurrences.
     */
    public function testEmptyBaselineReportsAllFindingsNew(): void
    {
        $application = $this->classify([], [$this->finding(line: 5), $this->finding(line: 9, symbol: 'App::handle()')]);

        self::assertCount(2, $application['new']);
        self::assertSame([], $application['unchanged']);
        self::assertSame(0, $application['report']->absentCount);
    }

    /**
     * Verify a second occurrence on one declaration is new rather than covered by the single reviewed occurrence.
     *
     * @return void - Asserts one unchanged occurrence and one new occurrence against a single reviewed occurrence.
     */
    public function testASecondOccurrenceBeyondTheReviewedCountIsNew(): void
    {
        $duplicate = $this->classify([$this->finding()], [$this->finding(), $this->finding()]);

        self::assertCount(1, $duplicate['unchanged']);
        self::assertCount(1, $duplicate['new']);
    }

    /**
     * Verify surplus reviewed occurrences are reported resolved when fewer are present.
     *
     * @return void - Asserts two unchanged occurrences and one absent occurrence when three reviewed occurrences decrease to two.
     */
    public function testSurplusReviewedOccurrencesAreResolved(): void
    {
        $decrease = $this->classify([$this->finding(), $this->finding(), $this->finding()], [$this->finding(), $this->finding()]);

        self::assertCount(2, $decrease['unchanged']);
        self::assertSame(1, $decrease['report']->absentCount);
    }

    /**
     * Verify occurrences beyond the reviewed count are new while the reviewed ones stay unchanged.
     *
     * @return void - Asserts two unchanged occurrences and one new occurrence when two reviewed occurrences increase to three.
     */
    public function testOccurrencesBeyondTheReviewedCountAreNew(): void
    {
        $increase = $this->classify([$this->finding(), $this->finding()], [$this->finding(), $this->finding(), $this->finding()]);

        self::assertCount(2, $increase['unchanged']);
        self::assertCount(1, $increase['new']);
    }

    /**
     * Verify the lower-line occurrence consumes the single reviewed count even when it appears later in scan order.
     *
     * @return void - Asserts that the early occurrence is unchanged and the late occurrence is new.
     */
    public function testReviewedCountIsSpentLowestLineFirst(): void
    {
        $late  = $this->finding(line: 300, symbol: null, message: 'File is a hotspot.');
        $early = $this->finding(line: 10, symbol: null, message: 'File is a hotspot.');

        $application = $this->classify([$this->finding(symbol: null, message: 'File is a hotspot.')], [$late, $early]);

        self::assertSame([$early], $application['unchanged']);
        self::assertSame([$late], $application['new']);
    }

    /**
     * Verify a new sibling never inherits a review, whether the symbol or the file changed.
     *
     * @return void - Asserts one new finding and one absent reviewed occurrence for each symbol-change and file-change case.
     */
    public function testReplacementAndRenameAreReviewWorthy(): void
    {
        $replaced = $this->classify([$this->finding()], [$this->finding(symbol: 'App::handle()')]);
        self::assertCount(1, $replaced['new']);
        self::assertSame(1, $replaced['report']->absentCount);

        $renamed = $this->classify([$this->finding()], [$this->finding(filePath: 'src/Gadget.php')]);
        self::assertCount(1, $renamed['new']);
        self::assertSame(1, $renamed['report']->absentCount);
    }

    /**
     * Verify one reviewed method occurrence yields one unchanged finding and one new finding when two are present.
     *
     * @return void - Asserts the unchanged and new partition sizes without selecting which occurrence matched.
     */
    public function testTwoFindingsOnOneMethodShareOneIdentityAndSpendItsCount(): void
    {
        $application = $this->classify([$this->finding(line: 10)], [$this->finding(line: 10), $this->finding(line: 90)]);

        self::assertCount(1, $application['unchanged']);
        self::assertCount(1, $application['new']);
    }

    /**
     * Verify the earlier variable finding remains unchanged and the later one is reported new.
     *
     * @return void - Asserts exact unchanged and new finding lists, no absent occurrences, and per-file, per-name ordinals.
     */
    public function testVariableSymbolsAreRankedByDeclarationLine(): void
    {
        $first  = $this->finding(ruleId: 'naming.identifier-quality', symbol: '$value', line: 10);
        $second = $this->finding(ruleId: 'naming.identifier-quality', symbol: '$value', line: 90);

        $application = $this->classify([$first], [$first, $second]);

        self::assertSame([$first], $application['unchanged']);
        self::assertSame([$second], $application['new']);
        self::assertSame(0, $application['report']->absentCount);

        // Ordinals count same-named declarations in one file: another name or another file starts again at 1.
        $otherName = $this->finding(ruleId: 'naming.identifier-quality', symbol: '$other', line: 5);
        $otherFile = $this->finding(ruleId: 'naming.identifier-quality', symbol: '$value', line: 5, filePath: 'src/Gadget.php');
        $ordinals  = BaselineIdentity::assignOrdinals([$first, $second, $otherName, $otherFile]);
        self::assertSame([1, 2, 1, 1], array_map(static fn(Finding $finding): int => $ordinals[spl_object_id($finding)], [$first, $second, $otherName, $otherFile]));
    }

    /**
     * Verify a clean earlier occurrence of a variable keeps its ordinal slot, so the review stays on the occurrence it was given to.
     *
     * @return void - Asserts that the later reviewed finding stays unchanged while the earlier finding is new with the shared inventory.
     */
    public function testAnEarlierCleanVariableOccurrenceKeepsItsOrdinalSlot(): void
    {
        $inventory = new DeclarationInventory(['src/App.php' => ['$value' => [10, 90]]]);
        $reviewed  = $this->finding(ruleId: 'docs.var-annotation-description', symbol: '$value', line: 90);
        $earlier   = $this->finding(ruleId: 'docs.var-annotation-description', symbol: '$value', line: 10);

        $application = (new BaselineFilter())->apply($this->baseline([$reviewed], $inventory), [$earlier, $reviewed], false, $inventory);

        self::assertSame([$reviewed], $application['unchanged']);
        self::assertSame([$earlier], $application['new']);
    }

    /**
     * Verify a finding named by a positional label, such as `closure@31`, keeps its review when code is inserted above it.
     * Two labelled declarations in one file stay apart, and fixing another rule's finding on a closure renumbers none of them.
     *
     * @return void - Asserts the line-free subject, per-file and per-label ordinals, unchanged shifted closures, and rule-local ranks.
     */
    public function testPositionalLabelsKeepTheirReviewWhenLinesShift(): void
    {
        $first     = $this->finding(ruleId: 'naming.short-variable', symbol: 'closure@31', line: 31);
        $second    = $this->finding(ruleId: 'naming.short-variable', symbol: 'closure@40', line: 40);
        $arrow     = $this->finding(ruleId: 'naming.short-variable', symbol: 'arrow@20', line: 20);
        $elsewhere = $this->finding(ruleId: 'naming.short-variable', symbol: 'closure@10', line: 10, filePath: 'src/Gadget.php');

        self::assertSame('class@anonymous#1', BaselineIdentity::subject($this->finding(symbol: 'class@anonymous:40', line: 40), 1));

        // Each label ranks by its line among the same file's labels of its kind, so two closures never share a SARIF fingerprint.
        $ordinals = BaselineIdentity::assignOrdinals([$first, $second, $arrow, $elsewhere]);
        self::assertSame([1, 2, 1, 1], array_map(static fn(Finding $finding): int => $ordinals[spl_object_id($finding)], [$first, $second, $arrow, $elsewhere]));

        $shifted = $this->classify([$first, $second], [
            $this->finding(ruleId: 'naming.short-variable', symbol: 'closure@33', line: 33),
            $this->finding(ruleId: 'naming.short-variable', symbol: 'closure@42', line: 42),
        ]);
        self::assertSame([], $shifted['new']);
        self::assertSame(0, $shifted['report']->absentCount);

        $onlyFirstReviewed = $this->classify([$first], [$first, $second]);
        self::assertSame([$first], $onlyFirstReviewed['unchanged']);
        self::assertSame([$second], $onlyFirstReviewed['new']);

        $otherRule           = $this->finding(ruleId: 'naming.identifier-quality', symbol: 'closure@31', line: 31);
        $afterOtherRuleFixed = $this->classify([$otherRule, $second], [$second]);
        self::assertSame([$second], $afterOtherRuleFixed['unchanged']);
    }

    /**
     * Verify rewording preserves a symbol-bearing match but makes a symbol-less finding new and its reviewed row absent.
     *
     * @return void - Asserts one unchanged symbol-bearing finding and a new finding plus an absent occurrence for the file-level case.
     */
    public function testMessageRewordingOnlyMattersWithoutASymbol(): void
    {
        $symbolBearing = $this->classify([$this->finding()], [$this->finding(message: 'Reworded in a patch release.')]);
        self::assertCount(1, $symbolBearing['unchanged']);

        $fileLevel = $this->classify(
            [$this->finding(symbol: null, message: 'File has no class docblock.')],
            [$this->finding(symbol: null, message: 'This file has no class docblock.')],
        );
        self::assertCount(1, $fileLevel['new']);
        self::assertSame(1, $fileLevel['report']->absentCount);
    }

    /**
     * Verify a sensitive finding stays visible and is counted as ineligible while an unmatched baseline row is reported absent.
     *
     * @return void - Asserts the retained finding, empty unchanged set, and ineligible and absent counts.
     */
    public function testSensitiveFindingsAreNeverEligible(): void
    {
        $secret  = $this->finding(ruleId: 'sensitive-data.aws-access-key', message: 'Possible AWS access key.', pillar: Pillar::SensitiveData, symbol: null);
        $hostile = new BaselineData('gruff-baseline.json', 'php', [new BaselineEntry('0000000000000000', 1)]);

        $application = (new BaselineFilter())->apply($hostile, [$secret], false);

        self::assertSame([$secret], $application['findings']);
        self::assertSame([], $application['unchanged']);
        self::assertSame(1, $application['report']->notEligibleCount);

        self::assertSame(1, $application['report']->absentCount);
    }

    /**
     * Verify a baseline written by another port is refused rather than applied.
     *
     * @return void - Asserts BaselineException with a message identifying the foreign port.
     */
    public function testForeignBaselineIsRefused(): void
    {
        $foreign = new BaselineData('gruff-baseline.json', 'ts', []);

        $this->expectException(BaselineException::class);
        $this->expectExceptionMessage('written by ts');

        (new BaselineFilter())->apply($foreign, [$this->finding()], false);
    }

    /**
     * Verify a diff-scoped run never marks unscanned reviewed debt resolved.
     *
     * @return void - Asserts skipped stale evaluation, zero absent occurrences, and no stale entries.
     */
    public function testDiffScopeSkipsAbsentEvaluation(): void
    {
        $baseline    = $this->baseline([$this->finding(), $this->finding(symbol: 'App::handle()')]);
        $application = (new BaselineFilter())->apply($baseline, [$this->finding()], true);

        self::assertSame('not-evaluated-diff-scope', $application['report']->staleEvaluation);
        self::assertSame(0, $application['report']->absentCount);
        self::assertSame([], $application['report']->staleEntries);
    }

    /**
     * Verify a separator-bearing symbol has no identity and stays visible while an ordinary reviewed finding is suppressed.
     *
     * @return void - Asserts identity availability, ordinary eligibility, the retained finding, and the ineligible count.
     */
    public function testSeparatorBearingSymbolIsReportedWithoutAnIdentity(): void
    {
        $reviewed  = $this->finding();
        $separated = $this->finding(symbol: 'App::process()#2');

        self::assertTrue(BaselineIdentity::hasIdentity($reviewed));
        self::assertFalse(BaselineIdentity::hasIdentity($separated));
        // The missing identity does not change the finding's pillar-based eligibility.
        self::assertTrue(BaselineIdentity::isEligible($separated));

        $application = (new BaselineFilter())->apply($this->baseline([$reviewed]), [$reviewed, $separated], false);

        self::assertSame([$separated], $application['findings']);
        self::assertSame(1, $application['report']->notEligibleCount);
    }

    /**
     * Verify all-digit hex identities resolve without PHP int-key TypeError and minimal resolved rows fall back to identity labels.
     *
     * @return void - Asserts strict string identity on resolved entries and clean identity fallback formatting.
     */
    public function testNumericHexIdentityResolvesCleanlyAndFormatsMinimalSummary(): void
    {
        $numericIdentity = '1000000000000000';
        $baseline        = new BaselineData('gruff-baseline.json', 'php', [new BaselineEntry($numericIdentity, 2)]);

        $application = (new BaselineFilter())->apply($baseline, [], false);

        self::assertCount(1, $application['report']->staleEntries);
        $resolved = $application['report']->staleEntries[0];
        self::assertSame($numericIdentity, $resolved->identity);
        self::assertSame(2, $resolved->count);
        self::assertSame('1000000000000000 (resolved 2)', $resolved->resolvedSummary());
        self::assertSame('`1000000000000000` (resolved 2)', $resolved->resolvedSummary(quoteCode: true));
    }

    /**
     * Build a baseline from reviewed findings and classify a run against it.
     *
     * @param list<Finding> $reviewed - Findings the user reviewed.
     * @param list<Finding> $currentFindings - Findings from the current scan.
     *
     * @return array{findings: list<Finding>, new: list<Finding>, unchanged: list<Finding>, collisions: list<array{identity: string, ruleId: string, path: string, subjects: list<string>}>, report: \GruffPhp\Results\Baseline\BaselineReport} - The filter's partition.
     */
    private function classify(array $reviewed, array $currentFindings): array
    {
        return (new BaselineFilter())->apply($this->baseline($reviewed), $currentFindings, false);
    }

    /**
     * Build a test baseline by counting the supplied findings, including findings that generation would exclude.
     *
     * @param list<Finding> $reviewed - Findings to include in fixture rows without eligibility filtering.
     * @param DeclarationInventory|null $inventory - Scanned variable occurrences for ordinals; null ranks among supplied findings alone.
     *
     * @return BaselineData - One row per identity with its count.
     */
    private function baseline(array $reviewed, ?DeclarationInventory $inventory = null): BaselineData
    {
        $ordinals = BaselineIdentity::assignOrdinals($reviewed, $inventory);
        $rows     = [];

        foreach ($reviewed as $finding) {
            $identity        = BaselineIdentity::identityOf($finding, $ordinals[spl_object_id($finding)] ?? 0);
            $rows[$identity] = ($rows[$identity] ?? 0) + 1;
        }

        ksort($rows, SORT_STRING);

        $entries = [];

        foreach ($rows as $identity => $count) {
            $entries[] = new BaselineEntry($identity, $count);
        }

        return new BaselineData('gruff-baseline.json', 'php', $entries);
    }

    /**
     * Build a live finding for matching assertions.
     *
     * @param string      $ruleId - Rule identifier emitted for the finding.
     * @param string      $message - Finding message; part of the identity only when no symbol is named.
     * @param int|null    $line - Source line for spend order and variable ordinals; null represents an unlocated finding.
     * @param string|null $symbol - Symbol the finding is anchored to; null for a file-level finding.
     * @param string      $filePath - Project-relative path, part of the identity.
     * @param Pillar      $pillar - Pillar; SensitiveData makes the finding ineligible.
     *
     * @return Finding - One warning finding with the given identity fields.
     */
    private function finding(
        string $ruleId = 'security.dangerous-function-call',
        string $message = 'Dangerous PHP execution pattern detected: eval.',
        ?int $line = 1,
        ?string $symbol = 'App::process()',
        string $filePath = 'src/App.php',
        Pillar $pillar = Pillar::Security,
    ): Finding {
        return new Finding(
            ruleId:     $ruleId,
            message:    $message,
            filePath:   $filePath,
            line:       $line,
            severity:   Severity::Warning,
            pillar:     $pillar,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
            symbol:     $symbol,
        );
    }
}
