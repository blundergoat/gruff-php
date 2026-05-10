<?php

declare(strict_types=1);

namespace Fixtures\Naming;

class HungarianFixture
{
    public function withHungarian(): void
    {
        $strName = 'hello';
        $arrItems = [1, 2, 3];
        $intCount = 5;
        $boolReady = true;
        $objUser = new \stdClass();

        $name = 'clean';
        $items = [1, 2];
        $strategy = 'also clean';
    }
}
