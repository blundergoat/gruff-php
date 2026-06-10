<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Config\ConfigLoader;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\FileLengthRule;

/**
 * Covers ConfigLoader compatibility with configs naming unknown rule ids: blocks for retired or mistyped rules are skipped instead of failing the
 * load.
 */
final class ConfigLoaderUnknownRuleIdTest extends ConfigLoaderTestCase
{
    /**
     * Verify unknown rule ids in a rules block are ignored without failing the load.
     *
     * @return void
     */
    public function testIgnoresUnknownRuleIdsInRulesBlock(): void
    {
        $registry = RuleRegistry::defaults();
        self::assertFalse($registry->has('size.nope'));

        $config = (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/Config/unknown-rule.yaml',
            $registry,
        );

        // The unknown block is dropped; registered rules keep their registry defaults.
        self::assertTrue($config->ruleSettings(FileLengthRule::ID)->enabled);
    }

    /**
     * Verify a config still carrying every retired project-rule block loads as registry defaults.
     *
     * @return void
     */
    public function testIgnoresRetiredProjectRuleBlocks(): void
    {
        $registry = RuleRegistry::defaults();
        foreach ([
                     'dead-code.unused-internal-class',
                     'dead-code.unused-internal-constant',
                     'dead-code.unused-internal-function',
                     'design.single-implementor-interface',
                 ] as $retiredRuleId) {
            self::assertFalse(
                $registry->has($retiredRuleId),
                sprintf('Retired rule id "%s" must no longer resolve in the default registry.', $retiredRuleId),
            );
        }

        $config = (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/Config/retired-project-rules.yaml',
            $registry,
        );

        self::assertTrue($config->ruleSettings(FileLengthRule::ID)->enabled);
    }
}
