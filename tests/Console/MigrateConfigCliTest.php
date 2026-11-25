<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigMigrator;
use GruffPhp\Rules\RuleRegistry;
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

    /**
     * Verifies retired rule IDs are stripped across rules and selection lists while legacy aliases rename and unknown typos stay intact.
     *
     * @return void - Asserts retired removals, docs.missing-phpdoc rename/deduplication, and preserved unknown rule IDs.
     */
    public function testStripsRetiredRuleIdsAndRenamesLegacyAliasWhilePreservingUnknownTypos(): void
    {
        $yaml = <<<'YAML'
schemaVersion: gruff-php.config.v0.1
selection:
    rules:
        - docs.missing-public-phpdoc
        - docs.missing-phpdoc
        - security.weak-crypto
        - size.mistyped-selection
    excludeRules:
        - test-quality.setup-bloat
        - docs.missing-public-phpdoc
        - naming.unknown-typo
rules:
    size.file-length:
        enabled: true
        threshold: 800
        severity: warning
    docs.missing-public-phpdoc:
        enabled: false
    test-quality.setup-bloat:
        enabled: true
    security.weak-crypto:
        enabled: true
    waste.one-line-method:
        enabled: true
    size.mistyped-rule:
        enabled: true
YAML;

        $result = ConfigMigrator::migrate($yaml);
        $parsed = Yaml::parse($result['text']);
        self::assertIsArray($parsed);

        $selection = $parsed['selection'] ?? null;
        $rules     = $parsed['rules'] ?? null;
        self::assertIsArray($selection);
        self::assertIsArray($rules);

        self::assertSame(['docs.missing-phpdoc', 'size.mistyped-selection'], $selection['rules']);
        self::assertSame(['docs.missing-phpdoc', 'naming.unknown-typo'], $selection['excludeRules']);
        self::assertSame(['size.file-length', 'docs.missing-phpdoc', 'size.mistyped-rule'], array_keys($rules));
        self::assertSame(['enabled' => false], $rules['docs.missing-phpdoc']);
        self::assertContains('Remove 3 retired rule override(s) from rules.', $result['changes']);
        self::assertContains('Rename legacy rule "docs.missing-public-phpdoc" to "docs.missing-phpdoc" under rules.', $result['changes']);
        self::assertContains('Remove 1 retired rule id(s) from selection.rules.', $result['changes']);
        self::assertContains('Remove 1 retired rule id(s) from selection.excludeRules.', $result['changes']);

        $emptiedWithPillar = ConfigMigrator::migrate("schemaVersion: gruff-php.config.v0.1\nselection:\n    pillars:\n        - security\n    rules:\n        - security.weak-crypto\n");
        self::assertStringContainsString('rules: []', $emptiedWithPillar['text']);

        // Refusing an all-retired selection.rules with no tier/pillar fallback prevents silently widening to all rules.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Every rule in selection.rules was retired in 0.6.0');
        ConfigMigrator::migrate("schemaVersion: gruff-php.config.v0.1\nselection:\n    rules:\n        - security.weak-crypto\n");
    }

    /**
     * Verifies migration rejects conflicting alias/canonical rule blocks, leaves current YAML unchanged,
     * and keeps the retired rule list strictly disjoint from active registered rules.
     *
     * @return void - Asserts conflict rejection, idempotency on current YAML, and retired-vs-active disjointness.
     */
    public function testRejectsConflictingAliasBlocksKeepsCurrentYamlByteIdenticalAndStaysDisjointFromActiveRules(): void
    {
        $registry = RuleRegistry::defaults();
        foreach (ConfigMigrator::retiredRuleIds() as $retiredRuleId) {
            self::assertFalse($registry->has($retiredRuleId), sprintf('Retired rule id "%s" must not overlap an active rule.', $retiredRuleId));
        }

        $current   = "schemaVersion: gruff-php.config.v0.1\nfailOn:\n    analyse: error\nrules:\n    docs.missing-phpdoc:\n        enabled: true\n";
        $unchanged = ConfigMigrator::migrate($current);
        self::assertSame([], $unchanged['changes']);
        self::assertSame($current, $unchanged['text']);

        $conflict = "schemaVersion: gruff-php.config.v0.1\nrules:\n    docs.missing-public-phpdoc:\n        enabled: false\n    docs.missing-phpdoc:\n        enabled: true\n";
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Migration input sets both legacy "docs.missing-public-phpdoc" and "docs.missing-phpdoc" under rules.');
        ConfigMigrator::migrate($conflict);
    }
}
