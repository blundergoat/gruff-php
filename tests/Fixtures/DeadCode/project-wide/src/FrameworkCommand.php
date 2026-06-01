<?php

declare(strict_types=1);

namespace App;

#[FixtureAttribute]
#[\Symfony\Component\Console\Attribute\AsCommand('app:framework-entrypoint')]
final class FrameworkCommand
{
}
