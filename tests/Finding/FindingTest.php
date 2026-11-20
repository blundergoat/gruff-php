<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Finding;

use GruffPhp\Output\Hook\HookFindingFilter;
use GruffPhp\Output\Hook\HookFindingIdentity;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Covers finding serialisation, remediation metadata, and identity comparisons.
 * Checks position-sensitive fingerprints against identities that survive line shifts.
 * Exercises hook suppression when remediation metadata is the only change.
 */
final class FindingTest extends TestCase
{
    /**
     * Checks the complete serialised finding payload and the format of both hashes.
     *
     * @return void - Asserts field values and 16-character lowercase hexadecimal hashes.
     */
    public function testSerializesStableFindingShape(): void
    {
        $finding = new Finding(
            ruleId:           'size.file-length',
            message:          'File is too long.',
            filePath:         'src/Example.php',
            line:             10,
            severity:         Severity::Warning,
            pillar:           Pillar::Size,
            tier:             RuleTier::V01,
            confidence:       Confidence::High,
            endLine:          20,
            column:           4,
            symbol:           'Example',
            remediation:      'Split the file.',
            secondaryPillars: [Pillar::Maintainability],
            metadata:         ['lines' => 401, 'threshold' => 400],
        );

        self::assertSame([
                             'ruleId' => 'size.file-length',
                                                                                                                                                                             'message' => 'File is too long.',
                                                                                                                                                                                                                 'file' => 'src/Example.php',
                                                                                                                                                                                                                 'line' => 10,
                                                                                                                                                                                                                 'endLine' => 20,
                                                                                                                                                                                                                             'column' => 4,
                                                                                                                                                                                                                             'symbol' => 'Example',
                                                                                                                                                                                                                             'severity' => 'warning',
                                                                                                                                                                                                                                                                                                                             'pillar' => 'size',
                                                                                                                                                                                                                                                                                                                             'secondaryPillars' => ['maintainability'],
                                                                                                                                                                                                                                                                                                                                                                                                        'tier' => 'v0.1',
                                                                                                                                                                                                                                                                                                                                                                                                        'confidence' => 'high',
                                                                                                                                                                                                                                                                                                                                                                                                                                'remediation' => 'Split the file.',
                                                                                                                                                                                                                                                                                                                                                                                                                                                                 'fingerprint' => $finding->fingerprint(),
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                'stableIdentity' => $finding->stableIdentity(),
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        'metadata' => ['lines' => 401, 'threshold' => 400],
                         ], $finding->toArray());
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $finding->fingerprint());
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $finding->stableIdentity());
    }

    /**
     * Verify remediation metadata round-trips without becoming part of finding identity.
     *
     * @return void - Asserts action metadata, metadata round-tripping, and equality of both identity hashes.
     */
    public function testRemediationMetadataIsAdditiveAndIdentityNeutral(): void
    {
        $plain      = $this->finding(line: 10, symbol: 'Example::doWork()', ruleId: 'naming.boolean-prefix');
        $serialized = $plain->toArray();
        $serialized['metadata'] = RemediationAction::Consider->metadata(
            'rules.naming.boolean-prefix.options.acceptedBooleanNames',
        );
        $classified = Finding::fromArray($serialized);

        self::assertSame(
            ['remediationAction' => 'APPLY'],
            RemediationAction::Apply->metadata(),
        );
        self::assertSame(
            ['remediationAction' => 'CONFIGURE'],
            RemediationAction::Configure->metadata(),
        );
        self::assertSame(
            [
                'remediationAction' => 'CONSIDER',
                'configurationKey' => 'rules.naming.boolean-prefix.options.acceptedBooleanNames',
            ],
            $classified->metadata,
        );
        self::assertSame($plain->fingerprint(), $classified->fingerprint());
        self::assertSame($plain->stableIdentity(), $classified->stableIdentity());
        self::assertSame($classified->metadata, Finding::fromArray($classified->toArray())->metadata);
    }

    /**
     * Verify hook new-only filtering ignores additive remediation metadata.
     *
     * @return void - Asserts equal hook identities, no retained findings, and one suppression.
     */
    public function testHookBaselineIdentityIgnoresRemediationMetadata(): void
    {
        $plain      = $this->finding(line: 10, symbol: 'Example::doWork()', ruleId: 'naming.boolean-prefix');
        $serialized = $plain->toArray();
        $serialized['metadata'] = RemediationAction::Consider->metadata(
            'rules.naming.boolean-prefix.options.acceptedBooleanNames',
        );
        $classified                 = Finding::fromArray($serialized);

        $plainHookIdentities      = HookFindingIdentity::forFindings([$plain]);
        $classifiedHookIdentities = HookFindingIdentity::forFindings([$classified]);
        $plainHookIdentity        = $plainHookIdentities[spl_object_id($plain)];
        $classifiedHookIdentity   = $classifiedHookIdentities[spl_object_id($classified)];

        $filterResult = (new HookFindingFilter())->apply(
            [$classified],
            null,
            [$plainHookIdentity => true],
            true,
        );

        self::assertSame($plainHookIdentity, $classifiedHookIdentity);
        self::assertSame([], $filterResult->findings);
        self::assertSame(1, $filterResult->suppressedCount);
    }

    /**
     * Verify stableIdentity ignores line shifts when symbol is set.
     *
     * @return void - Asserts equal stable identities and different fingerprints after the line shift.
     */
    public function testStableIdentitySurvivesLineShiftsWhenSymbolIsSet(): void
    {
        $atLine10 = $this->finding(line: 10, symbol: 'Example::doWork()');
        $atLine42 = $this->finding(line: 42, symbol: 'Example::doWork()');

        self::assertSame($atLine10->stableIdentity(), $atLine42->stableIdentity());
        self::assertNotSame($atLine10->fingerprint(), $atLine42->fingerprint());
    }

    /**
     * Checks line-shift invariance when neither finding has a symbol.
     *
     * @return void - Asserts equal stable identities and different fingerprints for null-symbol findings.
     */
    public function testStableIdentityFallsBackToMessageWhenSymbolIsNull(): void
    {
        $atLine10 = $this->finding(line: 10, symbol: null);
        $atLine99 = $this->finding(line: 99, symbol: null);

        self::assertSame($atLine10->stableIdentity(), $atLine99->stableIdentity());
        self::assertNotSame($atLine10->fingerprint(), $atLine99->fingerprint());
    }

    /**
     * Verify stableIdentity diverges across different rule IDs even at the same symbol.
     *
     * @return void - Asserts different stable identities for different rule IDs at one symbol.
     */
    public function testStableIdentityDifferentRuleIdsProduceDifferentValues(): void
    {
        $sizeRule       = $this->finding(line: 10, ruleId: 'size.file-length', symbol: 'Example::doWork()');
        $complexityRule = $this->finding(line: 10, ruleId: 'complexity.cognitive', symbol: 'Example::doWork()');

        self::assertNotSame($sizeRule->stableIdentity(), $complexityRule->stableIdentity());
    }

    /**
     * Checks that different missing-parameter messages produce distinct stable identities at the same symbol.
     *
     * @return void - Asserts different stable identities for the two missing-parameter findings.
     */
    public function testStableIdentitySeparatesSameSymbolFindingsByMessage(): void
    {
        $missingFoo = new Finding(
            ruleId:     'docs.missing-param-tag',
            message:    '@param $foo missing for Example::doWork().',
            filePath:   'src/Example.php',
            line:       10,
            severity:   Severity::Advisory,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
            symbol:     'Example::doWork()',
        );
        $missingBar = new Finding(
            ruleId:     'docs.missing-param-tag',
            message:    '@param $bar missing for Example::doWork().',
            filePath:   'src/Example.php',
            line:       10,
            severity:   Severity::Advisory,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
            symbol:     'Example::doWork()',
        );

        self::assertNotSame($missingFoo->stableIdentity(), $missingBar->stableIdentity());
    }

    /**
     * Build a Finding fixture for stable-identity tests.
     *
     * @param int         $line - Source line the fixture finding points at; varied across cases to probe identity.
     * @param string|null $symbol - Symbol used by identity checks; null creates a finding without a symbol.
     * @param string      $ruleId - Rule assigned to the fixture; defaults to size.file-length.
     *
     * @return Finding - Size/Warning fixture with a fixed message and path; endLine is ten lines after line.
     */
    private function finding(
        int     $line,
        ?string $symbol,
        string  $ruleId = 'size.file-length',
    ): Finding {
        return new Finding(
            ruleId:     $ruleId,
            message:    'File is too long.',
            filePath:   'src/Example.php',
            line:       $line,
            severity:   Severity::Warning,
            pillar:     Pillar::Size,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
            endLine:    $line + 10,
            column:     4,
            symbol:     $symbol,
        );
    }
}
