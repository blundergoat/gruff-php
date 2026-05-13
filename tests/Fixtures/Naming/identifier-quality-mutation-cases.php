<?php

declare(strict_types=1);

class Temp
{
    private string $stuff = 'value';

    private string $Temp = 'value';

    public function temp(string $foo, string $obj): string
    {
        $item = $foo;
        $item2 = $item;
        $HTTP2Value1 = $item2;

        return $HTTP2Value1 . $obj;
    }

    public function configure(): void
    {
    }

    public function provideData(): array
    {
        return [];
    }

    public function itemProvider(): array
    {
        return [];
    }

    public function loopAndCatchVariables(array $records): string
    {
        $value = '';

        foreach ($records as $item => $thing) {
            $value .= $item . $thing;
        }

        try {
            return $value;
        } catch (\RuntimeException $tmp) {
            return $tmp->getMessage();
        }
    }

    public function countedVariables(string $source): string
    {
        $item = $source;
        $thing = 'single-use';

        return $item;
    }
}

interface Data
{
}

trait HelperThing
{
}

enum Stuff
{
    case Value;
}

function bar(string $obj): string
{
    $tmp = $obj;

    return $tmp;
}

new class () {
};
