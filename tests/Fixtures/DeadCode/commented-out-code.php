<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

class CommentedOutCodeFixture
{
    public function active(): int
    {
        return 1;
    }

    // $this->doSomething();
    // $result = $service->process($input);
    // if ($x > 0) { return $x; }

    /* Normal comment explaining the design */

    /**
     * This is a regular docblock.
     */
    public function documented(): void
    {
    }
}
