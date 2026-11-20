<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Cli\Command\AnalysisFindingSupport;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Covers project-rule finding scoping in AnalysisFindingSupport.
 * Checks retained rule IDs and file paths for empty and populated requested-file sets.
 * Checks that an empty project-rule list leaves the supplied findings unchanged.
 */
final class AnalysisFindingSupportTest extends TestCase
{
    /**
     * Verify an empty discovered-file set drops project-rule findings instead of leaking whole-project context.
     *
     * @return void - Asserts that only the non-project rule ID remains.
     */
    public function testFilterProjectRuleFindingsDropsProjectFindingsWhenNoFilesDiscovered(): void
    {
        $support  = new AnalysisFindingSupport();
        $findings = [
            $this->finding('dead-code.unused-internal-class', 'src/Other.php'),
            $this->finding('test-quality.weak-assertion', 'src/Requested.php'),
        ];

        $filtered = $support->filterProjectRuleFindingsToFiles(
            $findings,
            ['dead-code.unused-internal-class'],
            [],
        );

        self::assertSame(
            ['test-quality.weak-assertion'],
            array_map(static fn (Finding $finding): string => $finding->ruleId, $filtered),
        );
    }

    /**
     * Verify project-rule findings stay scoped to the requested files when files are discovered.
     *
     * @return void - Asserts the retained paths are the requested project file and the other-rule file.
     */
    public function testFilterProjectRuleFindingsKeepsProjectFindingsInsideRequestedFiles(): void
    {
        $support  = new AnalysisFindingSupport();
        $findings = [
            $this->finding('dead-code.unused-internal-class', 'src/Requested.php'),
            $this->finding('dead-code.unused-internal-class', 'src/Other.php'),
            $this->finding('test-quality.weak-assertion', 'src/Other.php'),
        ];

        $filtered = $support->filterProjectRuleFindingsToFiles(
            $findings,
            ['dead-code.unused-internal-class'],
            ['src/Requested.php'],
        );

        self::assertSame(
            ['src/Requested.php', 'src/Other.php'],
            array_map(static fn (Finding $finding): string => $finding->filePath, $filtered),
        );
    }

    /**
     * Verify findings pass through untouched when no project rules are enabled.
     *
     * @return void - Asserts that filtering returns the original findings array.
     */
    public function testFilterProjectRuleFindingsReturnsAllWhenNoProjectRules(): void
    {
        $support  = new AnalysisFindingSupport();
        $findings = [$this->finding('dead-code.unused-internal-class', 'src/Other.php')];

        self::assertSame(
            $findings,
            $support->filterProjectRuleFindingsToFiles($findings, [], []),
        );
    }

    /**
     * Build a minimal advisory finding for the given rule id and file path.
     *
     * @param string $ruleId - Rule id to attach to the finding.
     * @param string $filePath - File path to attach to the finding.
     *
     * @return Finding - Minimal finding used for filter assertions.
     */
    private function finding(string $ruleId, string $filePath): Finding
    {
        return new Finding(
            ruleId:     $ruleId,
            message:    'message',
            filePath:   $filePath,
            line:       1,
            severity:   Severity::Advisory,
            pillar:     Pillar::TestQuality,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );
    }
}
