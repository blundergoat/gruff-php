<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Exercises a 0.5 YAML migration through the same console command a project maintainer runs.
 *
 * Checks that a hard-linked destination is rejected and that the original YAML survives both attempts.
 * The accepted output retains the command threshold under the current config key.
 */
final class MigrateConfigCliTest extends CliTestCase
{
    /**
     * Covers an aliased destination and a successful migration of valid legacy YAML.
     *
     * @return void - Confirms the source survives and the new file carries the exit gate.
     */
    public function testMigratesLegacyYamlWithoutWritingThroughAnAlias(): void
    {
        $project  = $this->tempDir();
        $original = "---\nminimumSeverity: {analyse: warning}\nallowlists:\n  secretPreviews:\n    - fixture-preview\n\n    - other-preview\n";
        $command  = [PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'migrate-config', '--config=old.yaml'];

        try {
            file_put_contents($project . '/old.yaml', $original);
            self::assertTrue(link($project . '/old.yaml', $project . '/alias.yaml'));
            $aliased = new Process([...$command, '--output=alias.yaml'], $project);
            self::assertSame(2, $aliased->run(), $aliased->getOutput());
            self::assertSame($original, file_get_contents($project . '/old.yaml'));

            $migration = new Process([...$command, '--output=new.yaml'], $project);
            self::assertSame(0, $migration->run(), $migration->getOutput() . $migration->getErrorOutput());
            self::assertSame($original, file_get_contents($project . '/old.yaml'));
            self::assertSame([
                'schemaVersion' => 'gruff-php.config.v0.1',
                'failOn' => ['analyse' => 'warning'],
            ], Yaml::parseFile($project . '/new.yaml'));
        } finally {
            $this->removeDir($project);
        }
    }
}
