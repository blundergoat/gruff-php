<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Command\DashboardStateFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Covers DashboardStateFactory behavior.
 */
final class DashboardStateFactoryTest extends TestCase
{
    /**
     * Verify console input is converted into complete dashboard defaults.
     *
     * @return void No return value.
     */
    public function testDefaultQueryUsesConsoleInputAndFlags(): void
    {
        $state = (new DashboardStateFactory())->defaultQuery($this->input([
            'paths' => ['src', 'tests'],
            '--fail-on' => 'error',
            '--config' => 'custom.yaml',
            '--baseline' => 'baseline.json',
            '--no-baseline' => true,
            '--no-config' => true,
            '--diff' => true,
            '--include-ignored' => true,
        ]), '/repo');

        self::assertSame([
            'project' => '/repo',
            'paths' => 'src tests',
            'scanScope' => 'diff',
            'failOn' => 'error',
            'config' => 'custom.yaml',
            'baseline' => 'baseline.json',
            'noBaseline' => '1',
            'noConfig' => '1',
            'includeIgnored' => '1',
            'reportInteractive' => '0',
        ], $state);
    }

    /**
     * Verify default query falls back to conventional paths and config.
     *
     * @return void No return value.
     */
    public function testDefaultQueryFallsBackToConventions(): void
    {
        $state = (new DashboardStateFactory())->defaultQuery($this->input(), '/repo');

        self::assertSame('.', $state['paths']);
        self::assertSame('full', $state['scanScope']);
        self::assertSame('none', $state['failOn']);
        self::assertSame('.gruff.yaml', $state['config']);
        self::assertSame('', $state['baseline']);
        self::assertSame('0', $state['noBaseline']);
        self::assertSame('0', $state['noConfig']);
        self::assertSame('0', $state['includeIgnored']);
        self::assertSame('0', $state['reportInteractive']);
    }

    /**
     * Verify request state overrides defaults and coerces checkbox-like values.
     *
     * @return void No return value.
     */
    public function testStateMergesQueryAndCoercesControls(): void
    {
        $state = (new DashboardStateFactory())->state($this->input([
            'paths' => ['src'],
            '--fail-on' => 'warning',
            '--diff' => true,
            '--include-ignored' => true,
        ]), '/repo', [
            'project' => '../other',
            'paths' => 'lib',
            'scanScope' => 'sideways',
            'failOn' => 'error',
            'config' => '',
            'baseline' => 'custom-baseline.json',
            'noBaseline' => 'yes',
            'noConfig' => '1',
            'includeIgnored' => '0',
            'reportInteractive' => '1',
        ]);

        self::assertSame([
            'project' => '../other',
            'paths' => 'lib',
            'scanScope' => 'full',
            'failOn' => 'error',
            'config' => '',
            'baseline' => 'custom-baseline.json',
            'noBaseline' => '0',
            'noConfig' => '1',
            'includeIgnored' => '0',
            'reportInteractive' => '1',
        ], $state);
    }

    /**
     * Verify project root resolution handles relative, absolute, and invalid paths.
     *
     * @return void No return value.
     */
    public function testProjectRootResolution(): void
    {
        $factory = new DashboardStateFactory();
        $base    = dirname(__DIR__, 2);

        self::assertSame($base, $factory->initialProjectRoot($this->input(), $base));
        self::assertSame($base . '/src', $factory->resolveProjectRoot('src', $base));
        self::assertSame($base . '/tests', $factory->resolveProjectRoot($base . '/tests', '/tmp'));
        self::assertNull($factory->resolveProjectRoot('missing-project-root', $base));
        self::assertNull($factory->initialProjectRoot($this->input(['--project' => 'missing-project-root']), $base));
    }

    /**
     * Verify optional string options reject empty values.
     *
     * @return void No return value.
     */
    public function testOptionalStringOptionRejectsEmptyValues(): void
    {
        $factory = new DashboardStateFactory();

        self::assertNull($factory->optionalStringOption($this->input(['--config' => '']), 'config'));
        self::assertSame('custom.yaml', $factory->optionalStringOption($this->input(['--config' => 'custom.yaml']), 'config'));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function input(array $parameters = []): ArrayInput
    {
        return new ArrayInput($parameters, new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL),
            new InputOption('project', null, InputOption::VALUE_REQUIRED),
            new InputOption('fail-on', null, InputOption::VALUE_REQUIRED, '', 'none'),
            new InputOption('config', null, InputOption::VALUE_REQUIRED),
            new InputOption('baseline', null, InputOption::VALUE_OPTIONAL),
            new InputOption('no-baseline', null, InputOption::VALUE_NONE),
            new InputOption('no-config', null, InputOption::VALUE_NONE),
            new InputOption('diff', null, InputOption::VALUE_NONE),
            new InputOption('include-ignored', null, InputOption::VALUE_NONE),
        ]));
    }
}
