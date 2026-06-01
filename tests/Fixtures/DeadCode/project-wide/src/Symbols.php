<?php

declare(strict_types=1);

namespace App;

final class UsedClass
{
}

trait UsedTrait
{
}

final class TraitConsumer
{
    use UsedTrait;
}

final class AliasReferencedClass
{
}

final class ClassConstantReferencedClass
{
}

final class StaticReferencedClass
{
    public static function ping(): string
    {
        return 'pong';
    }
}

final class TypeReferencedClass
{
}

interface ImplementedInterface
{
}

final class InterfaceConsumer implements ImplementedInterface
{
}

#[\Attribute]
final class FixtureAttribute
{
}

enum UsedEnum
{
    case One;
}

final class UnusedClass
{
}

trait UnusedTrait
{
}

enum UnusedEnum
{
    case One;
}

final class TestOnlyClass
{
}

final class SelfOnlyClass
{
    public function copy(): self
    {
        return new self();
    }
}

const USED_CONSTANT = 'used';
const UNUSED_CONSTANT = 'unused';
const TEST_ONLY_CONSTANT = 'test';

function used_function(): string
{
    return USED_CONSTANT;
}

function unused_function(): string
{
    return 'unused';
}

function test_only_function(): string
{
    return 'test';
}

function first_class_callable_function(): string
{
    return 'callable';
}

function recursive_unused_function(): string
{
    return recursive_unused_function();
}
