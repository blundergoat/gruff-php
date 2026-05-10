<?php

declare(strict_types=1);

namespace Fixtures\Naming;

class BooleanPrefixFixture
{
    public function isActive(): bool { return true; }
    public function hasPermission(): bool { return true; }
    public function canEdit(): bool { return true; }
    public function shouldRetry(): bool { return false; }
    public function containsValue(): bool { return true; }
    public function matchesPattern(): bool { return true; }
    public function supportsFeature(): bool { return true; }

    public function active(): bool { return true; }
    public function enabled(): bool { return true; }
    public function check(): bool { return false; }

    public function getName(): string { return ''; }
}
