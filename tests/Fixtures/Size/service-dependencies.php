<?php

declare(strict_types=1);

namespace Fixtures\Size;

final class ServiceWithTooManyDependencies
{
    public function __construct(
        private DependencyA $a,
        private DependencyB $b,
        private DependencyC $c,
        private DependencyD $d,
        private DependencyE $e,
        private DependencyF $f,
        private DependencyG $g,
        private DependencyH $h,
        private DependencyI $i,
        private DependencyJ $j,
        private DependencyK $k,
        private DependencyL $l,
    ) {
    }
}

final class DependencyA {}
final class DependencyB {}
final class DependencyC {}
final class DependencyD {}
final class DependencyE {}
final class DependencyF {}
final class DependencyG {}
final class DependencyH {}
final class DependencyI {}
final class DependencyJ {}
final class DependencyK {}
final class DependencyL {}
