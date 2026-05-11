<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

final class RedundantVariableFixture
{
    public function immediatelyReturned(): string
    {
        $result = $this->value();

        return $result;
    }

    public function immediatelyReturnedInBranch(bool $flag): string
    {
        if ($flag) {
            $branchResult = $this->value();

            return $branchResult;
        }

        return 'fallback';
    }

    public function usedBeforeReturn(): string
    {
        $result = $this->value();
        $result = trim($result);

        return $result;
    }

    public function differentReturnedVariable(): string
    {
        $result = $this->value();
        $fallback = 'fallback';

        return $fallback;
    }

    private function value(): string
    {
        return 'value';
    }
}
