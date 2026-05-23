<?php

declare(strict_types=1);

namespace Fixtures\Docs\MissingConstant;

/**
 * Holds two constants, one documented and one not.
 */
final class DocumentedConstants
{
    /**
     * Always-present constant.
     */
    public const DOCUMENTED = 'yes';

    public const UNDOCUMENTED = 'no';
}

/**
 * Documented enum - cases are exempt at the case level.
 */
enum DocumentedEnum: string
{
    case FIRST = 'first';
    case SECOND = 'second';
}

enum UndocumentedEnum: string
{
    case FIRST = 'first';
    case SECOND = 'second';
}
