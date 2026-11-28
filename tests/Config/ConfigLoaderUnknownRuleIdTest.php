<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\FileLengthRule;

/**
 * Exercises loading configuration files containing unknown or retired rule IDs.
 * Checks that the fixture IDs are absent from the default registry and loading still succeeds.
 * Asserts that the registered file-length rule remains enabled.
 */
final class ConfigLoaderUnknownRuleIdTest extends ConfigLoaderTestCase
{
    /**
     * Verify unknown rule ids in a rules block are ignored without failing the load.
     *
     * @return void - Asserts size.nope is unregistered and loading leaves the file-length rule enabled.
     */
    public function testIgnoresUnknownRuleIdsInRulesBlock(): void
    {
        $registry = RuleRegistry::defaults();
        self::assertFalse($registry->has('size.nope'));

        $config = (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/Config/unknown-rule.yaml',
            $registry,
        );


        self::assertTrue($config->ruleSettings(FileLengthRule::ID)->enabled);
    }

    /**
     * Verify retired project-rule blocks load without disabling the registered file-length rule.
     *
     * @return void - Asserts the fixture's retired IDs are unregistered and the file-length rule stays enabled.
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
