<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Baseline;

use GruffPhp\Results\Baseline\BaselineData;
use GruffPhp\Results\Baseline\BaselineEntry;
use GruffPhp\Results\Baseline\BaselineException;
use GruffPhp\Results\Baseline\BaselineFilter;
use GruffPhp\Results\Finding\BaselineIdentity;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Covers v3 matching as users meet it: reviewed debt that survives line movement, siblings that never inherit a review, count-aware
 * budgets, a fixed spend order, collisions that hide nothing, secrets that are never eligible, and refusal of another port's file.
 */
final class BaselineFilterTest extends TestCase
{
    /**
     * Verify the identity reproduces the digests the family oracle pins for other ports.
     *
     * @return void
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
     * Verify a measurement never enters a symbol-less identity, so a file that grew keeps the identity the user reviewed.
     *
     * @return void
     */
    public function testMeasuredValuesNeverEnterASymbolLessIdentity(): void
    {
        self::assertSame('File has # lines (limit #)', BaselineIdentity::normaliseMeasuredValues('File has 1010 lines (limit 1000)'));
        self::assertSame('#% over # lines in v#', BaselineIdentity::normaliseMeasuredValues('12.5% over 1,234 lines in v0.5.2'));
        self::assertSame('File has no module documentation', BaselineIdentity::normaliseMeasuredValues('File has no module documentation'));
    }

    /**
     * Verify the ratified invariant: a baseline removes reviewed ordinary findings from score and exit, and nothing else.
     *
     * @return void
     */
    public function testABaselineOnlyEverRemovesReviewedFindings(): void
    {
        $reviewed = $this->finding();
        $fresh    = $this->finding(line: 400, symbol: 'App::other()');
        $secret   = $this->finding(ruleId: 'sensitive-data.aws-access-key', message: 'Possible AWS key.', symbol: null, pillar: Pillar::SensitiveData);

        $application = (new BaselineFilter())->apply($this->baseline([$reviewed, $secret]), [$reviewed, $fresh, $secret], false);

        // Only the reviewed finding leaves the gated set; the new one and the secret still fail the run.
        self::assertSame([$fresh, $secret], $application['findings']);
        self::assertSame(1, $application['report']->unchangedCount);
        self::assertSame(1, $application['report']->newCount);
        self::assertSame(1, $application['report']->notEligibleCount);
    }

    /**
     * Verify a line-shifted finding still matches its reviewed identity.
     *
     * @return void
     */
    public function testLineShiftedFindingStaysUnchanged(): void
    {
        $application = $this->classify([$this->finding(line: 10)], [$this->finding(line: 310)]);

        self::assertSame([], $application['new']);
        self::assertCount(1, $application['unchanged']);
        self::assertSame(0, $application['report']->absentCount);
    }

    /**
     * Verify an empty baseline reports every finding as new.
     *
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
     */
    public function testOccurrencesBeyondTheReviewedCountAreNew(): void
    {
        $increase = $this->classify([$this->finding(), $this->finding()], [$this->finding(), $this->finding(), $this->finding()]);

        self::assertCount(2, $increase['unchanged']);
        self::assertCount(1, $increase['new']);
    }

    /**
     * Verify the reviewed count is spent lowest line first, so two ports hide the same occurrence rather than merely the same number.
     *
     * @return void
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
     * @return void
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
     * Verify two findings on one method share one identity and spend its reviewed count: PHP cannot declare a method twice in one file,
     * so the second finding is new by count, never hidden by the first review.
     *
     * @return void
     */
    public function testTwoFindingsOnOneMethodShareOneIdentityAndSpendItsCount(): void
    {
        $application = $this->classify([$this->finding(line: 10)], [$this->finding(line: 10), $this->finding(line: 90)]);

        self::assertCount(1, $application['unchanged']);
        self::assertCount(1, $application['new']);
    }

    /**
     * Verify a variable symbol, which a file can declare in several places, is ranked by line so two bindings stay apart.
     *
     * @return void
     */
    public function testVariableSymbolsAreRankedByDeclarationLine(): void
    {
        $first  = $this->finding(ruleId: 'naming.identifier-quality', symbol: '$value', line: 10);
        $second = $this->finding(ruleId: 'naming.identifier-quality', symbol: '$value', line: 90);

        $application = $this->classify([$first], [$first, $second]);

        self::assertSame([$first], $application['unchanged']);
        self::assertSame([$second], $application['new']);
        self::assertSame(0, $application['report']->absentCount);
    }

    /**
     * Verify a message rewording expires only a file-level finding, which has nothing but its message to name it.
     *
     * @return void
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
     * Verify a sensitive finding is never hidden, even by a hand-written row claiming its identity, and never becomes a row.
     *
     * @return void
     */
    public function testSensitiveFindingsAreNeverEligible(): void
    {
        $secret  = $this->finding(ruleId: 'sensitive-data.aws-access-key', message: 'Possible AWS access key.', pillar: Pillar::SensitiveData, symbol: null);
        $hostile = new BaselineData('gruff-baseline.json', 'php', [new BaselineEntry('0000000000000000', 1)]);

        $application = (new BaselineFilter())->apply($hostile, [$secret], false);

        self::assertSame([$secret], $application['findings']);
        self::assertSame([], $application['unchanged']);
        self::assertSame(1, $application['report']->notEligibleCount);
        // The hand-written row matches nothing, so it is permanently stale rather than a suppression.
        self::assertSame(1, $application['report']->absentCount);
    }

    /**
     * Verify a baseline written by another port is refused rather than applied.
     *
     * @return void
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
     * @return void
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
     * Build the baseline a generate run would write for the reviewed findings.
     *
     * @param list<Finding> $reviewed - Findings the user reviewed.
     *
     * @return BaselineData - One row per identity with its count.
     */
    private function baseline(array $reviewed): BaselineData
    {
        $ordinals = BaselineIdentity::assignOrdinals($reviewed);
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
     * @param int|null    $line - Source line; varied to prove lines never enter the identity.
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
