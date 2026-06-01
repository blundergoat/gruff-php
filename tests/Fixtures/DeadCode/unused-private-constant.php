<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

class UnusedPrivateConstantFixture
{
    private const USED_BY_SELF = 'self';

    private const USED_BY_STATIC = 'static';

    private const USED_BY_THIS = 'this';

    private const USED_BY_OWN_NAME = 'own';

    private const USED_IN_DEFAULT = 'default';

    private const USED_IN_ARRAY_DEFAULT = 'array';

    private const UNUSED = 'dead';

    protected const PROTECTED_CONSTANT = 'protected';

    public const PUBLIC_CONSTANT = 'public';

    private array $values = [self::USED_IN_ARRAY_DEFAULT];

    public function expose(string $value = self::USED_IN_DEFAULT): array
    {
        return [
            $value,
            self::USED_BY_SELF,
            static::USED_BY_STATIC,
            $this::USED_BY_THIS,
            UnusedPrivateConstantFixture::USED_BY_OWN_NAME,
            self::PROTECTED_CONSTANT,
            self::PUBLIC_CONSTANT,
            $this->values,
        ];
    }
}

trait PrivateConstantTraitFixture
{
    private const USED_TRAIT = 'used';

    private const UNUSED_TRAIT = 'unused';

    public function traitValue(): string
    {
        return self::USED_TRAIT;
    }
}

enum PrivateConstantEnumFixture: string
{
    case Live = 'live';

    private const USED_ENUM = 'used';

    private const UNUSED_ENUM = 'unused';

    public function label(): string
    {
        return self::USED_ENUM;
    }
}

class DynamicPrivateConstantFixture
{
    private const MAYBE_DYNAMIC = 'dynamic';

    public function value(string $name): string
    {
        return self::{$name};
    }
}

class InheritedConstantParentFixture
{
    protected const INHERITED_PROTECTED = 'protected';

    public const INHERITED_PUBLIC = 'public';
}

class InheritedConstantChildFixture extends InheritedConstantParentFixture
{
    public function inheritedValues(): array
    {
        return [
            self::INHERITED_PROTECTED,
            self::INHERITED_PUBLIC,
        ];
    }
}
