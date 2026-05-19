<?php

declare(strict_types=1);

namespace Fixtures\Naming;

class AbbreviationAllowlistFixture
{
    private string $cfg = 'bad';

    public function acceptedNames(string $id, string $fqn): void
    {
        $raw = $id . $fqn;
        $uri = 'https://example.test/' . $raw;

        echo $uri;
    }

    public function flaggedNames(string $db): void
    {
        $tmp = $db;

        echo $tmp . $this->cfg;
    }

    public function loopAndCatchExemptions(array $rows): void
    {
        foreach ($rows as $key => $row) {
            echo $key . $row;
        }

        foreach ($rows as [$start, $end]) {
            echo $start . $end;
        }

        try {
            echo 'ok';
        } catch (\RuntimeException $ex) {
            echo $ex->getMessage();
        }
    }
}
