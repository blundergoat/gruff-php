<?php

declare(strict_types=1);

class Thing
{
    private string $stuff = 'value';

    public function temp(string $foo, string $userName): string
    {
        $item  = $foo;
        $item2 = $item;
        $api   = 'ok';
        $value = 'allowed';

        for ($i = 0; $i < 2; $i++) {
            $value .= (string) $i;
        }

        try {
            return $item2 . $api . $userName;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }

    public function provideThings(): array
    {
        return [];
    }

    public function calculateInvoiceTotal(string $customerId): string
    {
        $invoiceTotal = $customerId;

        return $invoiceTotal;
    }
}

class LoopVariableFixture
{
    public function shortItemLoop(array $items): void
    {
        foreach ($items as $item) {
            $this->accept($item);
        }
    }

    public function longItemLoop(array $items): void
    {
        foreach ($items as $item) {
            $firstName  = (string) $item;
            $secondName = $firstName;
            $thirdName  = $secondName;
            $fourthName = $thirdName;
        }
    }

    public function longRowLoop(array $rows): void
    {
        foreach ($rows as $row) {
            $firstName  = (string) $row;
            $secondName = $firstName;
            $thirdName  = $secondName;
            $fourthName = $thirdName;
        }
    }

    public function longMapLoop(array $values): void
    {
        foreach ($values as $key => $value) {
            $firstName  = (string) $key;
            $secondName = (string) $value;
            $thirdName  = $firstName;
            $fourthName = $secondName . $thirdName;
        }
    }

    private function accept(mixed $input): void
    {
    }
}

interface Data
{
}

trait HelperThing
{
}

function bar(string $obj): string
{
    $tmp = $obj;

    return $tmp;
}
