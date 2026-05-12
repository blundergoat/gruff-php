<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Command\DashboardScanCommandBuilder;
use PHPUnit\Framework\TestCase;

final class DashboardScanCommandBuilderTest extends TestCase
{
    public function testParsePathsDropsOptionPrefixedEntries(): void
    {
        $builder = new DashboardScanCommandBuilder('/tmp/gruff');

        self::assertSame(['.'], $builder->parsePaths('--evil'));
        self::assertSame(['.'], $builder->parsePaths('. --evil'));
        self::assertSame(['src', 'tests'], $builder->parsePaths('src tests'));
    }

    public function testAnalyseCommandSeparatesOwnedOptionsFromUserPaths(): void
    {
        $builder        = new DashboardScanCommandBuilder('/tmp/gruff');
        $command        = $builder->analyseCommand(['src', 'tests'], $this->state());
        $separatorIndex = array_search('--', $command, true);

        self::assertIsInt($separatorIndex);
        self::assertSame(['src', 'tests'], array_slice($command, $separatorIndex + 1));
        self::assertSame('--fail-on', $command[5]);
        self::assertSame('none', $command[6]);
    }

    public function testOptionLikePathCannotReachProducedArgumentVector(): void
    {
        $builder = new DashboardScanCommandBuilder('/tmp/gruff');
        $paths   = $builder->parsePaths('--generate-baseline /tmp/leak.json --config evil.yaml');
        $command = $builder->analyseCommand($paths, $this->state());

        self::assertSame(['/tmp/leak.json', 'evil.yaml'], $paths);
        self::assertNotContains('--generate-baseline', $command);
        self::assertNotContains('--config', array_slice($command, array_search('--', $command, true) ?: 0));
        self::assertContains('--', $command);
    }

    /**
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, reportInteractive: string}
     */
    private function state(): array
    {
        return [
            'project' => __DIR__,
            'paths' => '.',
            'scanScope' => 'full',
            'failOn' => 'none',
            'config' => '.gruff.yaml',
            'baseline' => '',
            'noBaseline' => '0',
            'noConfig' => '0',
            'includeIgnored' => '0',
            'reportInteractive' => '0',
        ];
    }
}
