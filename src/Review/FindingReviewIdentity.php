<?php

declare(strict_types=1);

namespace GruffPhp\Review;

use GruffPhp\Finding\Finding;

final readonly class FindingReviewIdentity
{
    public function key(Finding $finding): string
    {
        return implode("\0", [
            $finding->filePath,
            $finding->ruleId,
            $finding->symbol ?? $finding->message,
        ]);
    }
}
