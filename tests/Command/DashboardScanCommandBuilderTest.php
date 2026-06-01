<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Command\DashboardScanCommandBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Covers dashboard scan command building: dash-prefixed and quoted path token preservation, owned-option separation, and prevention of option-like paths reaching the argument vector.
 */
final class DashboardScanCommandBuilderTest extends TestCase
{
    /**
     * Verify parse paths preserves dash-prefixed and quoted path entries.
     *
     * @return void
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
     * @return void
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
     * @return void
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
     * Verify option like path cannot reach produced argument vector.
     *
     * @return void
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
     * Build the dashboard scan state used by command-builder tests.
     *
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, reportInteractive: string}
     */
    private function state(): array
    {
        // Canonical valid scan state the builder turns into a command line.
        return [
            'project' => __DIR__,
            'paths' => '.',
            'scanScope' => 'full',
            'failOn' => 'none',
            'config' => '.gruff-php.yaml',
            'baseline' => '',
            'noBaseline' => '0',
            'noConfig' => '0',
            'includeIgnored' => '0',
            'reportInteractive' => '0',
        ];
    }
}
