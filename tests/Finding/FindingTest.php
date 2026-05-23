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
 * Covers FindingTest behavior.
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
            'metadata' => ['lines' => 401, 'threshold' => 400],
        ], $finding->toArray());
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $finding->fingerprint());
    }
}
