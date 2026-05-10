<?php

declare(strict_types=1);

class Thing
{
    private string $stuff = 'value';

    public function temp(string $foo, string $userName): string
    {
        $item = $foo;
        $item2 = $item;
        $api = 'ok';
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
