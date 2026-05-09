<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

use GruffPhp\Finding\Finding;

final readonly class DiffFindingFilter
{
    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    public function filter(array $findings, DiffResult $diff): array
    {
        if (!$diff->active) {
            return $findings;
        }

        return array_values(array_filter(
            $findings,
            static function (Finding $finding) use ($diff): bool {
                if (!$diff->hasFile($finding->filePath)) {
                    return false;
                }

                $line = $finding->line;
                if ($line === null) {
                    return true;
                }

                $ranges = $diff->rangesFor($finding->filePath);
                if ($ranges === []) {
                    return true;
                }

                $endLine = $finding->endLine ?? $line;

                foreach ($ranges as $range) {
                    if ($range->touches($line, $endLine)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}
