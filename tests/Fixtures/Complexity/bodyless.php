<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Complexity;

/**
 * Contract whose method is a bodyless signature, used to confirm the
 * executable-complexity rules skip declarations with no control flow (P6).
 */
interface BodylessContract
{
    /**
     * Bodyless interface method: declares a contract, has nothing to measure.
     *
     * @return int Implementation-defined count.
     */
    public function declaredCount(): int;
}

/**
 * Pairs a bodyless abstract method with a concrete body so a test can assert the
 * complexity rules measure only the executable method, not the signatures.
 */
abstract class BodylessFixture implements BodylessContract
{
    /**
     * Bodyless abstract method: subclasses supply the control flow to measure.
     *
     * @return int Implementation-defined total.
     */
    abstract public function abstractTotal(): int;

    /**
     * Concrete method with a real body, the only node the rules should score.
     *
     * @return int A constant total.
     */
    public function concreteTotal(): int
    {
        return 1;
    }
}
