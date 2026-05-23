<?php

declare(strict_types=1);

namespace App\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class LocalMarker
{
}

namespace App\Attribute\Symfony;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AutoconfigureTag
{
}

namespace Fixtures\Design\SingleImplementor\MutationCases;

use App\Attribute\LocalMarker;
use App\Attribute\Symfony\AutoconfigureTag;

function anonymousClassFixture(): object
{
    return new class {
    };
}

interface ParentContract
{
}

interface ChildContract extends ParentContract
{
}

final class ParentContractImpl implements ParentContract
{
}

interface ReturnUsageInterface
{
}

final class ReturnUsageImpl implements ReturnUsageInterface
{
}

final class ReturnUsageConsumer
{
    public function make(): ReturnUsageInterface
    {
        return new ReturnUsageImpl();
    }
}

interface PropertyUsageInterface
{
}

final class PropertyUsageImpl implements PropertyUsageInterface
{
}

final class PropertyUsageConsumer
{
    public PropertyUsageInterface $service;
}

interface NullableUsageInterface
{
}

final class NullableUsageImpl implements NullableUsageInterface
{
}

final class NullableUsageConsumer
{
    public function __construct(?NullableUsageInterface $service)
    {
        unset($service);
    }
}

interface UnionUsageInterface
{
}

interface OtherUnionDependency
{
}

final class UnionUsageImpl implements UnionUsageInterface
{
}

final class UnionUsageConsumer
{
    public function __construct(UnionUsageInterface|OtherUnionDependency $service)
    {
        unset($service);
    }
}

interface IntersectionUsageInterface
{
}

interface OtherIntersectionDependency
{
}

final class IntersectionUsageImpl implements IntersectionUsageInterface
{
}

final class IntersectionUsageConsumer
{
    public function __construct(IntersectionUsageInterface&OtherIntersectionDependency $service)
    {
        unset($service);
    }
}

interface LocalParentInterface
{
}

interface MultiParentExternalInterface extends LocalParentInterface, \Stringable
{
}

final class MultiParentExternalImpl implements MultiParentExternalInterface
{
    public function __toString(): string
    {
        return '';
    }
}

#[LocalMarker]
#[\Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag('app.multi_attribute')]
interface MultipleAttributeInterface
{
}

final class MultipleAttributeImpl implements MultipleAttributeInterface
{
}

#[AutoconfigureTag]
interface ContainsAttributeInterface
{
}

final class ContainsAttributeImpl implements ContainsAttributeInterface
{
}

interface PositiveAfterSkipsInterface
{
}

final class PositiveAfterSkipsImpl implements PositiveAfterSkipsInterface
{
}

/**
 * Contract whose only external use is an instanceof check.
 */
interface InstanceofUsageInterface
{
}

/**
 * Sole implementor for the instanceof usage exemption fixture.
 */
final class InstanceofUsageImpl implements InstanceofUsageInterface
{
    /**
     * Return a stable value so the fixture class is not empty.
     *
     * @return string Non-empty marker value.
     */
    public function fixtureMarker(): string
    {
        return 'instanceof';
    }
}

/**
 * Consumer that checks the interface without a signature type-hint.
 */
final class InstanceofUsageConsumer
{
    /**
     * Check whether the candidate exposes the interface contract.
     *
     * @param object $candidate Value to inspect.
     * @return bool True when the candidate implements InstanceofUsageInterface.
     */
    public function matches(object $candidate): bool
    {
        return $candidate instanceof InstanceofUsageInterface;
    }
}
