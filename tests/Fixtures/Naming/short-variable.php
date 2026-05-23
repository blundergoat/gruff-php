<?php

declare(strict_types=1);

namespace Fixtures\Naming;

class ShortVariableFixture
{
    public function withShortVars(): void
    {
        $x = 1;
        $a = 2;
        $name = 'good';
    }

    public function loopCounters(): void
    {
        for ($i = 0; $i < 10; $i++) {
            for ($j = 0; $j < 10; $j++) {
                echo $i + $j;
            }
        }
    }

    public function catchVariable(): void
    {
        try {
            echo 'try';
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    public function twoCharVars(): void
    {
        $id = 1;
        $db = 'connection';
        $fn = 'value';
    }
}
