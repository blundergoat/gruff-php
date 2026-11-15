<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Cli\Dashboard\DashboardScanCommandBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Covers parsing of dashboard paths and construction of scan arguments.
 * Checks quoted path text and placement of supplied paths after --.
 * Verifies the fail-on value and forwarding of the deep-scan-budget override.
 */
final class DashboardScanCommandBuilderTest extends TestCase
{
    /**
     * Verify that parsing preserves dash-prefixed and quoted path entries.
     *
     * @return void - Asserts that parsing preserves dash-prefixed operands and keeps quoted words in one path.
     */
    public function testDashboardPathTokensPreserveDashPrefixedAndQuotedEntries(): void
    {
        $dashboardScanCommandBuilder = new DashboardScanCommandBuilder('/tmp/gruff');

        self::assertSame(['--fixture.php'], $dashboardScanCommandBuilder->parsePaths('--fixture.php'));
        self::assertSame(['.', '--fixture.php'], $dashboardScanCommandBuilder->parsePaths('. --fixture.php'));
        self::assertSame(['src', 'tests/Feature Cases'], $dashboardScanCommandBuilder->parsePaths('src "tests/Feature Cases"'));
    }

    /**
     * Verify quoted Windows paths keep literal backslashes.
     *
     * @return void - Asserts preserved Windows separators for both encodings and literal quotes in the quoted-name case.
     */
    public function testDashboardPathTokensPreserveQuotedBackslashes(): void
    {
        $dashboardScanCommandBuilder = new DashboardScanCommandBuilder('/tmp/gruff');

        self::assertSame(['C:\\temp files\\src'], $dashboardScanCommandBuilder->parsePaths('"C:\temp files\src"'));
        self::assertSame(['C:\\temp files\\src'], $dashboardScanCommandBuilder->parsePaths('"C:\\\\temp files\\\\src"'));
        self::assertSame(['fixtures/A "quoted" Case'], $dashboardScanCommandBuilder->parsePaths('"fixtures/A \\"quoted\\" Case"'));
    }

    /**
     * Verify analyse command separates owned options from user paths.
     *
     * @return void - Asserts the path suffix follows -- and the command contains the fixed fail-on option and value.
     */
    public function testAnalyseCommandSeparatesOwnedOptionsFromUserPaths(): void
    {
        $dashboardScanCommandBuilder = new DashboardScanCommandBuilder('/tmp/gruff');
        $command                     = $dashboardScanCommandBuilder->analyseCommand(['src', 'tests'], $this->state());
        $separatorIndex              = array_search('--', $command, true);

        self::assertIsInt($separatorIndex);
        self::assertSame(['src', 'tests'], array_slice($command, $separatorIndex + 1));
        self::assertSame('--fail-on', $command[5]);
        self::assertSame('none', $command[6]);
    }

    /**
     * Verify option-like paths appear after -- in the produced argument vector.
     *
     * @return void - Asserts that option-like paths are preserved after -- in the produced argument vector.
     */
    public function testOptionLikePathCannotReachProducedArgumentVector(): void
    {
        $dashboardScanCommandBuilder = new DashboardScanCommandBuilder('/tmp/gruff');
        $paths                       = $dashboardScanCommandBuilder->parsePaths('--generate-baseline /tmp/leak.json --config evil.yaml');
        $command                     = $dashboardScanCommandBuilder->analyseCommand($paths, $this->state());

        self::assertSame(['--generate-baseline', '/tmp/leak.json', '--config', 'evil.yaml'], $paths);
        self::assertSame($paths, array_slice($command, (array_search('--', $command, true) ?: 0) + 1));
        self::assertContains('--', $command);
    }

    /**
     * Verify the deep-scan-budget value appears before the path separator.
     *
     * @return void - Asserts that --deep-scan-budget has value 'off' and occurs before --.
     */
    public function testAnalyseCommandForwardsDeepScanBudget(): void
    {
        $state                   = $this->state();
        $state['deepScanBudget'] = 'off';
        $command                 = (new DashboardScanCommandBuilder('/tmp/gruff'))->analyseCommand(['src'], $state);
        $optionIndex             = array_search('--deep-scan-budget', $command, true);
        $separatorIndex          = array_search('--', $command, true);

        self::assertIsInt($optionIndex);
        self::assertIsInt($separatorIndex);
        self::assertSame('off', $command[$optionIndex + 1]);
        self::assertLessThan($separatorIndex, $optionIndex);
    }

    /**
     * Build the dashboard scan state used by command-builder tests.
     *
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string,
     *                        noConfig: string, deepScanBudget: string, includeIgnored: string, reportInteractive: string} - Command-builder inputs.
     */
    private function state(): array
    {

        return [
            'project' => __DIR__,
            'paths' => '.',
            'scanScope' => 'full',
            'failOn' => 'none',
            'config' => '.gruff-php.yaml',
            'baseline' => '',
            'noBaseline' => '0',
            'noConfig' => '0',
            'deepScanBudget' => '',
            'includeIgnored' => '0',
            'reportInteractive' => '0',
        ];
    }
}
