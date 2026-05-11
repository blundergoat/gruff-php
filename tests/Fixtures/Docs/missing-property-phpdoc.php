<?php

declare(strict_types=1);

namespace Fixtures\Docs\MissingProperty;

/**
 * Holds one documented and one undocumented property.
 */
final class DocumentedProperty
{
    /**
     * Identifier of the entity.
     */
    public string $documented = '';

    public string $undocumented = '';
}

/**
 * Constructor has a docblock with one matched @param.
 */
final class PromotedPropertyWithPartialDoc
{
    /**
     * Construct with one documented promoted param.
     *
     * @param int $documentedPromoted The known value.
     */
    public function __construct(
        public int $documentedPromoted,
        public int $undocumentedPromoted,
    ) {
    }
}

/**
 * Constructor has no docblock at all.
 */
final class PromotedPropertyNoDoc
{
    public function __construct(
        public int $first,
        public int $second,
    ) {
    }
}

/**
 * Anonymous inner class should not flag.
 */
final class AnonymousFactoryForProperty
{
    /**
     * Returns an anonymous class instance.
     */
    public function make(): object
    {
        return new class {
            public int $exempt = 0;
        };
    }
}
