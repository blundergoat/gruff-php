<?php

declare(strict_types=1);

namespace Fixtures\Modernisation;

final class PhpDocMixedOveruseFixture
{
    /**
     * @var string|mixed
     */
    public string $propertyUnionMixed = '';

    /**
     * @PSALM-VAR string|MIXED
     */
    public string $uppercasePsalmVar = '';

    /**
     * @var list<array<string, mixed>>
     */
    public array $listOfMixedVar = [];

    /**
     * @var mixed
     */
    public mixed $standaloneMixedVar = null;

    /**
     * @var string|mixed
     */
    public const RAW_PAYLOAD = 'raw';

    /**
     * Reads a context bag.
     *
     * @param array<string, mixed> $context
     */
    public function arrayShapeMixedParam(array $context): void
    {
        unset($context);
    }

    /**
     * Returns a shaped record.
     *
     * @return array{x: mixed}
     */
    public function arrayShapeMixedReturn(): array
    {
        return ['x' => 1];
    }

    /**
     * Text mentions @return mixed inline, but it is not a tag.
     */
    public function inlineAtSignTextOnly(): void
    {
    }

    /**
     * Accepts a standalone mixed value.
     *
     * @param mixed $foo
     */
    public function standaloneMixedParam(mixed $foo): void
    {
        unset($foo);
    }

    /**
     * Returns standalone mixed.
     *
     * @return mixed
     */
    public function standaloneMixedReturn(): mixed
    {
        return null;
    }

    /**
     * Untyped signature documented as mixed-only.
     *
     * @param mixed $x
     */
    public function untypedSignatureMixedDoc($x): void
    {
        unset($x);
    }

    /**
     * Non-scanned tags must not stop later scanned tags.
     *
     * @throws \RuntimeException when invalid.
     *
     * @return string|mixed
     */
    public function mixedAfterUnscannedTag(): string
    {
        return '';
    }

    /**
     * Non-mixed tags must not stop later mixed tags.
     *
     * @param string $name
     *
     * @return string|mixed
     */
    public function mixedAfterNonMixedTag(string $name): string
    {
        return $name;
    }

    /**
     * Allowed array bags must not stop later mixed tags.
     *
     * @param array<string, mixed> $context
     *
     * @return string|mixed
     */
    public function mixedAfterArrayBagTag(array $context): string
    {
        unset($context);

        return '';
    }

    /**
     * Signature-covered standalone mixed must not stop later mixed tags.
     *
     * @param mixed $value
     *
     * @return string|mixed
     */
    public function mixedAfterCoveredStandaloneTag(mixed $value): string
    {
        unset($value);

        return '';
    }

    /**
     * Uppercase tag and type should still be normalised.
     *
     * @RETURN MIXED
     */
    public function uppercaseMixedReturnTag(): string
    {
        return '';
    }

    /**
     * Method-local var tags are scanned when attached to a method docblock.
     *
     * @var string|mixed $local
     */
    public function methodVarTag(): void
    {
    }

    /**
     * Magic-property tags are scanned when attached to a method docblock.
     *
     * @property string|mixed $dynamic
     */
    public function methodPropertyTag(): void
    {
    }

    /**
     * Type aliases are scanned when attached to a method docblock.
     *
     * @phpstan-type Payload mixed
     */
    public function methodTypeAliasTag(): void
    {
    }

    /**
     * Accepts a nested array shape.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function nestedArrayShapeMixed(array $items): void
    {
        unset($items);
    }

    /**
     * Uppercase unstructured array bags are still allowed.
     *
     * @return Array<String, Mixed>
     */
    public function uppercaseArrayBagAllowed(): array
    {
        return [];
    }

    /**
     * Bag-looking suffixes are not unstructured array bags.
     *
     * @return array<string, mixed>Extra
     */
    public function arrayBagSuffixIsNotAllowed(): array
    {
        return [];
    }

    /**
     * Prefixed list-looking types are not unstructured bags.
     *
     * @return prefixlist<mixed>
     */
    public function prefixedListIsNotAllowed(): array
    {
        return [];
    }

    /**
     * List-looking suffixes are not unstructured bags.
     *
     * @return list<mixed>Suffix
     */
    public function listSuffixIsNotAllowed(): array
    {
        return [];
    }

    /**
     * Returns a union that contains mixed.
     *
     * @return string|mixed
     */
    public function unionWithMixed(): string
    {
        return '';
    }

    /**
     * Phpstan-flavoured tag.
     *
     * @phpstan-return array<string, mixed>
     */
    public function phpstanReturnMixed(): array
    {
        return [];
    }

    /**
     * Psalm-flavoured tag.
     *
     * @psalm-param list<array<string, mixed>> $rows
     */
    public function psalmParamMixed(array $rows): void
    {
        unset($rows);
    }

    /**
     * Generic template - bounded by T, not mixed.
     *
     * @template T
     *
     * @param array<int, T> $items
     *
     * @return list<T>
     */
    public function templateGeneric(array $items): array
    {
        return array_values($items);
    }

    /**
     * Documents a thrown exception, no value typing.
     *
     * @throws \RuntimeException when invalid.
     */
    public function throwsOnly(): void
    {
        throw new \RuntimeException('demo');
    }

    public function noDocblock(string $value): string
    {
        return $value;
    }

    /**
     * Plain descriptive text only, no tags.
     */
    public function descriptiveDocblockNoTags(): void
    {
    }

    /**
     * Iterable generic containing mixed.
     *
     * @return iterable<mixed>
     */
    public function mixedInIterable(): iterable
    {
        return [];
    }

    /**
     * Collection generic containing mixed.
     *
     * @return Collection<int, mixed>
     */
    public function mixedInCollection(): Collection
    {
        return new Collection();
    }

    /**
     * The description can mention mixed without making the return type mixed.
     *
     * @return bool True when an array/list bag has mixed payload leaves.
     */
    public function isMixedOnlyInReturnDescription(): bool
    {
        return true;
    }
}

/**
 * Stand-in for an external collection class so the fixture parses cleanly.
 */
final class Collection
{
}

/**
 * Function signatures with mixed already cover standalone @param mixed.
 *
 * @param mixed $value
 */
function standaloneFunctionMixedParam(mixed $value): void
{
    unset($value);
}

/**
 * Untyped functions still need PHPDoc mixed findings.
 *
 * @param mixed $value
 */
function untypedFunctionMixedParam($value): void
{
    unset($value);
}

/**
 * Function return signatures with mixed already cover standalone @return mixed.
 *
 * @return mixed
 */
function standaloneFunctionMixedReturn(): mixed
{
    return null;
}

/**
 * Untyped function returns still need PHPDoc mixed findings.
 *
 * @return mixed
 */
function untypedFunctionMixedReturn()
{
    return null;
}

/**
 * Union docs containing mixed are not redundant even when the return signature is mixed.
 *
 * @return string|mixed
 */
function functionUnionMixedDocOnMixedSignature(): mixed
{
    return '';
}
