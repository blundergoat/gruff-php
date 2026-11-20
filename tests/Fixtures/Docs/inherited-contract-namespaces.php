<?php

declare(strict_types=1);

namespace Fixture\Local {
    /**
     * An unrelated class that shares the vendor parent's short name.
     */
    class Base
    {
        /**
         * Local helper.
         *
         * @param int $limit Upper bound.
         *
         * @return int Count.
         */
        public function run(int $limit): int
        {
            return $limit;
        }
    }
}

namespace Fixture\Other {
    /**
     * Extends the vendor class, not the local one above, so the local docblock covers nothing here.
     */
    final class Job extends \Vendor\Lib\Base
    {
        /**
         * Runs the job up to a limit.
         */
        public function run(int $limit): int
        {
            return $limit;
        }

        /**
         * Carries an attribute that resolves to this namespace, not PHP's own Override.
         */
        #[Override]
        public function stop(int $code): int
        {
            return $code;
        }
    }
}
