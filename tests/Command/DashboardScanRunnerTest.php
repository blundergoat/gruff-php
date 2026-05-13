<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Command\DashboardPageRenderer;
use GruffPhp\Command\DashboardRequestContext;
use GruffPhp\Command\DashboardScanRunner;
use GruffPhp\Command\DashboardStateFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Covers DashboardScanRunner behavior.
 */
final class DashboardScanRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    /**
     * Remove temporary projects created by tests.
     *
     * @return void No return value.
     */
    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $tempDir) {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify full scans are cached until source fingerprints change.
     *
     * @return void No return value.
     */
    public function testFullScanUsesCacheUntilSourceFingerprintChanges(): void
    {
        $project = $this->projectDir();
        $runner = $this->runner($this->fakeGruffBinary('counter'));
        $context = $this->context($project);

        $first = $runner->scanHtml($context, ['paths' => 'src']);
        $second = $runner->scanHtml($context, ['paths' => 'src']);
        file_put_contents($project . '/src/Example.php', "<?php\nfinal class ExampleChanged {}\n");
        $third = $runner->scanHtml($context, ['paths' => 'src']);

        self::assertStringContainsString('scan 1', $first);
        self::assertStringContainsString('scan 1', $second);
        self::assertStringContainsString('scan 2', $third);
        self::assertSame('2', trim((string) file_get_contents($project . '/scan-count.txt')));
        self::assertStringContainsString('gruff-dashboard-meta', $first);
    }

    /**
     * Verify diff and include-ignored scans bypass the dashboard cache.
     *
     * @return void No return value.
     */
    public function testDiffAndIncludeIgnoredScansBypassCache(): void
    {
        $project = $this->projectDir();
        $runner = $this->runner($this->fakeGruffBinary('counter'));
        $context = $this->context($project);

        $runner->scanHtml($context, ['paths' => 'src', 'scanScope' => 'diff']);
        $runner->scanHtml($context, ['paths' => 'src', 'scanScope' => 'diff']);
        $runner->scanHtml($context, ['paths' => 'src', 'includeIgnored' => '1']);
        $runner->scanHtml($context, ['paths' => 'src', 'includeIgnored' => '1']);

        self::assertSame('4', trim((string) file_get_contents($project . '/scan-count.txt')));
    }

    /**
     * Verify invalid project roots render a dashboard error.
     *
     * @return void No return value.
     */
    public function testInvalidProjectRootRendersError(): void
    {
        $project = $this->projectDir();
        $runner = $this->runner($this->fakeGruffBinary('counter'));
        $html = $runner->scanHtml($this->context($project), ['project' => $project . '/missing']);

        self::assertStringContainsString('Project root is not an existing directory.', $html);
        self::assertStringContainsString('Exit code: 2', $html);
    }

    /**
     * Verify empty scan output renders stderr detail.
     *
     * @return void No return value.
     */
    public function testEmptyScanOutputRendersErrorDetail(): void
    {
        $project = $this->projectDir();
        $runner = $this->runner($this->fakeGruffBinary('empty'));
        $html = $runner->scanHtml($this->context($project), ['paths' => 'src']);

        self::assertStringContainsString('The scan did not produce HTML output.', $html);
        self::assertStringContainsString('empty-output', $html);
    }

    /**
     * Build a runner fixture.
     *
     * @param string $binary Fake gruff binary path.
     * @return DashboardScanRunner Fixture value.
     */
    private function runner(string $binary): DashboardScanRunner
    {
        return new DashboardScanRunner($binary, new DashboardStateFactory(), new DashboardPageRenderer());
    }

    /**
     * Build a request context for a project.
     *
     * @param string $project Project root.
     * @return DashboardRequestContext Fixture value.
     */
    private function context(string $project): DashboardRequestContext
    {
        return new DashboardRequestContext($this->input(), $project, $project, 5.0, '127.0.0.1', 8765);
    }

    /**
     * Build dashboard console input defaults.
     *
     * @return ArrayInput Fixture value.
     */
    private function input(): ArrayInput
    {
        return new ArrayInput([], new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL),
            new InputOption('fail-on', null, InputOption::VALUE_REQUIRED, '', 'none'),
            new InputOption('config', null, InputOption::VALUE_REQUIRED),
            new InputOption('baseline', null, InputOption::VALUE_OPTIONAL),
            new InputOption('no-baseline', null, InputOption::VALUE_NONE),
            new InputOption('no-config', null, InputOption::VALUE_NONE),
            new InputOption('diff', null, InputOption::VALUE_NONE),
            new InputOption('include-ignored', null, InputOption::VALUE_NONE),
        ]));
    }

    /**
     * Create a temporary dashboard project.
     *
     * @return string Project directory.
     */
    private function projectDir(): string
    {
        $project = sys_get_temp_dir() . '/gruff-dashboard-scan-' . bin2hex(random_bytes(6));
        mkdir($project . '/src', 0777, true);
        file_put_contents($project . '/src/Example.php', "<?php\nfinal class Example {}\n");
        $this->tempDirs[] = $project;

        return $project;
    }

    /**
     * Create a fake gruff executable.
     *
     * @param string $mode Fake executable mode.
     * @return string Binary path.
     */
    private function fakeGruffBinary(string $mode): string
    {
        $binary = sys_get_temp_dir() . '/gruff-dashboard-scan-bin-' . bin2hex(random_bytes(6)) . '.php';
        $script = $mode === 'empty'
            ? "<?php fwrite(STDERR, 'empty-output');\n"
            : <<<'PHP'
<?php
$counter = getcwd() . '/scan-count.txt';
$count = is_file($counter) ? (int) file_get_contents($counter) : 0;
$count++;
file_put_contents($counter, (string) $count);
echo '<!doctype html><html><body>scan ' . $count . '</body></html>';
PHP;

        file_put_contents($binary, $script);
        chmod($binary, 0755);

        return $binary;
    }

    /**
     * Recursively remove a temporary directory.
     *
     * @param string $directory Directory to remove.
     * @return void No return value.
     */
    private function removeDir(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = array_diff(scandir($directory) ?: [], ['.', '..']);

        foreach ($items as $item) {
            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($directory);
    }
}
