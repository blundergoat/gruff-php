<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AutoconfigureTag
{
    public function __construct(public readonly string $name = '')
    {
    }
}

namespace Fixtures\Design\SingleImplementor\SymfonyTagged;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Symfony DI tag attribute makes this interface a framework contract.
 *
 * Must not flag even with one implementor because the framework
 * discovers consumers via the tag.
 */
#[AutoconfigureTag('app.audit_listener')]
interface AuditListenerInterface
{
    public function onAudit(string $event): void;
}

/**
 * Single implementor; the rule must not flag because the interface is framework-tagged.
 */
final class SymfonyTaggedListener implements AuditListenerInterface
{
    public function onAudit(string $event): void
    {
    }
}
