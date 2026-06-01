<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Finding;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Covers serialisation of Finding into the documented stable shape.
 */
final class FindingTest extends TestCase
{
    /**
     * Verify serializes stable finding shape.
     *
     * @return void
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
     * Verify stableIdentity ignores line shifts when symbol is set.
     *
     * @return void
     */
    public function testStableIdentitySurvivesLineShiftsWhenSymbolIsSet(): void
    {
        $atLine10 = $this->finding(line: 10, symbol: 'Example::doWork()');
        $atLine42 = $this->finding(line: 42, symbol: 'Example::doWork()');

        self::assertSame($atLine10->stableIdentity(), $atLine42->stableIdentity());
        self::assertNotSame($atLine10->fingerprint(), $atLine42->fingerprint());
    }

    /**
     * Verify stableIdentity falls back to message text when symbol is null.
     *
     * @return void
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
     * @return void
     */
    public function testStableIdentityDifferentRuleIdsProduceDifferentValues(): void
    {
        $sizeRule       = $this->finding(line: 10, ruleId: 'size.file-length', symbol: 'Example::doWork()');
        $complexityRule = $this->finding(line: 10, ruleId: 'complexity.cognitive', symbol: 'Example::doWork()');

        self::assertNotSame($sizeRule->stableIdentity(), $complexityRule->stableIdentity());
    }

    /**
     * Verify two findings of the same rule on the same symbol but with different
     * messages stay distinct. `docs.missing-param-tag` emits one finding per
     * missing parameter under the same method symbol — those must not collapse
     * to one identity in external diff tooling.
     *
     * @return void
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
     * @param int         $line   Source line the fixture finding points at; varied across cases to probe identity.
     * @param string|null $symbol Enclosing symbol, or null when the finding is file-level rather than scoped.
     * @param string      $ruleId Rule the finding was raised under; defaulted so cases vary only line and symbol.
     * @return Finding
     */
    private function finding(
        int $line,
        ?string $symbol,
        string $ruleId = 'size.file-length',
    ): Finding {
        // Hand back the fully built fixture finding so a test can compare its stable identity.
        return new Finding(
            ruleId:           $ruleId,
            message:          'File is too long.',
            filePath:         'src/Example.php',
            line:             $line,
            severity:         Severity::Warning,
            pillar:           Pillar::Size,
            tier:             RuleTier::V01,
            confidence:       Confidence::High,
            endLine:          $line + 10,
            column:           4,
            symbol:           $symbol,
        );
    }
}
