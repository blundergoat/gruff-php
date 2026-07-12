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

/**
 * Exercises token-based Boolean state and proposition vocabulary for callable names.
 * The naming rule reaches these methods after prefix checks and exact-name configuration.
 * Users encounter the distinction when a subject-first contract reads clearly without `is`.
 */
final class BooleanStateVocabularyFixture
{
    /**
     * Report whether payment was requested through the default state suffix.
     *
     * @return bool - True when a payment request exists; false when none exists.
     */
    public function paymentRequested(): bool { return true; }

    /**
     * Report whether today's printable schedule was requested through a multi-token suffix.
     *
     * @return bool - True when the printable schedule was requested; false otherwise.
     */
    public function printableTodayScheduleRequested(): bool { return true; }

    /**
     * Report whether an explanation was requested through another multi-token suffix.
     *
     * @return bool - True when the explanation was requested; false otherwise.
     */
    public function declineCodeExplanationRequested(): bool { return true; }

    /**
     * Report the assistant intent as a subject-first proposition with context on both sides.
     *
     * @return bool - True when the intent requires context; false when it does not.
     */
    public function assistantIntentRequiresContext(): bool { return true; }

    /**
     * Retain a single-token callable that remains configuration-only.
     *
     * @return bool - True for a valid state; false for an invalid state.
     */
    public function valid(): bool { return true; }

    /**
     * Retain a single-token callable that remains configuration-only.
     *
     * @return bool - True when a resource is available; false when unavailable.
     */
    public function available(): bool { return true; }

    /**
     * Retain a single-token callable even though properties may accept the adjective.
     *
     * @return bool - True when resolution completed; false when unresolved.
     */
    public function resolved(): bool { return true; }

    /**
     * Retain a single-token callable even though properties may accept the adjective.
     *
     * @return bool - True when output is printable; false when it is not printable.
     */
    public function printable(): bool { return true; }

    /**
     * Retain a raw suffix fragment that is not the whole `requested` token.
     *
     * @return bool - True for the synthetic state; false otherwise.
     */
    public function unrequested(): bool { return true; }

    /**
     * Retain a noun token that must not be mistaken for the `requires` verb.
     *
     * @return bool - True when requirements context exists; false otherwise.
     */
    public function assistantRequirementsContext(): bool { return true; }

    /**
     * Retain a proposition with no predicate or context after its verb.
     *
     * @return bool - True for the synthetic incomplete proposition; false otherwise.
     */
    public function assistantIntentRequires(): bool { return true; }
}

/**
 * Report a snake-case state suffix on a free function.
 *
 * @return bool - True when the report was requested; false otherwise.
 */
function report_requested(): bool { return true; }

/**
 * Report a snake-case subject-first proposition on a free function.
 *
 * @return bool - True when the assistant intent requires context; false otherwise.
 */
function assistant_intent_requires_context(): bool { return true; }

/**
 * Retain a single-token free function as an explicit configuration decision.
 *
 * @return bool - True when output is printable; false when it is not printable.
 */
function printable(): bool { return true; }
