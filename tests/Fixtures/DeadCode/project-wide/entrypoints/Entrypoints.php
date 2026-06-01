<?php

declare(strict_types=1);

namespace App;

final class PathEntrypoint
{
}

const PATH_ENTRYPOINT_CONSTANT = 'path';

function path_entrypoint_function(): string
{
    return PATH_ENTRYPOINT_CONSTANT;
}
