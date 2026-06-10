<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Naming;

/**
 * Fixture covering the `naming.identifier-quality` iteration-callback escape hatch:
 * the sole parameter of a short closure passed directly to an array-iteration
 * callable reads as a loop variable, so loopBodyThreshold applies to it.
 */
final class CallbackParameterFixture
{
    /**
     * Skip: one-statement array_filter predicate - $item is a loop variable in a short body.
     */
    public function activeItems(array $items): array
    {
        return array_filter($items, function ($item) {
            return $item !== null;
        });
    }

    /**
     * Skip: arrow-function callback bodies count as a single statement.
     */
    public function itemLabels(array $items): array
    {
        return array_map(fn ($item) => (string) $item, $items);
    }

    /**
     * Fire: callback body at the threshold demands a meaningful parameter name.
     */
    public function expensiveEntries(array $entries): array
    {
        return array_filter($entries, function ($entry) {
            $firstName  = (string) $entry;
            $secondName = $firstName;
            $thirdName  = $secondName;
            return $thirdName !== '';
        });
    }

    /**
     * Fire: a generic parameter on a non-iteration callback gets no loop escape hatch.
     */
    public function queueRunner(callable $runner): mixed
    {
        return $runner(function ($data) {
            return $data;
        });
    }
}
