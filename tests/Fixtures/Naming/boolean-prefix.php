<?php

declare(strict_types=1);

namespace Fixtures\Naming;

class BooleanPrefixFixture
{
    public function isActive(): bool { return true; }
    public function hasPermission(): bool { return true; }
    public function canEdit(): bool { return true; }
    public function shouldRetry(): bool { return false; }
    public function wasReady(): bool { return true; }
    public function containsValue(): bool { return true; }
    public function looksLikeTestFile(): bool { return true; }
    public function matchesPattern(): bool { return true; }
    public function supportsFeature(): bool { return true; }
    public function allowsGuestAccess(): bool { return true; }
    public function requiresReview(): bool { return true; }
    public function usesCache(): bool { return true; }
    public function acceptsPayload(): bool { return true; }
    public function permitsRetry(): bool { return true; }
    public function includesArchivedRows(): bool { return true; }
    public function excludesInactivePatients(): bool { return true; }
    public function enablesPracticeAssistant(): bool { return true; }
    public function disablesLegacyFallback(): bool { return true; }
    public function supportsSelectedAnswerScope(): bool { return true; }
    public function requiresCreditPurchaseAccess(): bool { return true; }
    public function usesCodeOwnedAnswer(): bool { return true; }
    public function matchesPrompt(): bool { return true; }

    public function active(): bool { return true; }
    public function enabled(): bool { return true; }
    public function status(): bool { return true; }
    public function check(): bool { return false; }
    public function didRun(): bool { return true; }

    public function has_note_been_actioned(): bool { return true; }
    public function is_valid_state(): bool { return true; }

    public function hasty(): bool { return true; }
    public function isolate(): bool { return false; }

    public function getName(): string { return ''; }
}
