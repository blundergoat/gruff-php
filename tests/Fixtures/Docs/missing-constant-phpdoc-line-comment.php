<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Docs;

/**
 * Exercises local and grouped comments for constant documentation analysis.
 * The documentation rule scans these declarations in class-body order.
 * Users reach these shapes when concise comments describe related constant families.
 */
final class LineCommentedConstantFixture
{
    // Maximum byte length accepted by the streaming CSV parser before back-pressure kicks in.
    public const CSV_BYTE_CAP = 65536;

    // Stable telemetry key used by staff dashboards.
    public const TELEMETRY_KEY = 'practice_assistant.turn.completed';

    /**
     * Stable telemetry key used by staff dashboards.
     */
    public const DOCUMENTED_TELEMETRY_KEY = 'practice_assistant.turn.completed.documented';

    // Supported message roles stored in assistant chat history.
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    public const PLAIN_NO_COMMENT = 'plain';

    // Cache namespace used for per-session prompt payloads.
    private const PRIVATE_CACHE_PREFIX = 'practice-assistant:';

    private const PRIVATE_NO_COMMENT = 'missing';

    // constant
    private const PRIVATE_USELESS_COMMENT = 'constant';

    // Max pages.
    private const MAX_PAGES = 3;

    // TODO
    private const PRIVATE_TODO_COMMENT = 'todo';

    // Detached comments do not document the next private constant.

    private const PRIVATE_DETACHED_COMMENT = 'detached';

    /*
     * Shared metadata keys written into every cache payload.
     */
    private const PAYLOAD_VERSION_KEY = 'version',
        IDEMPOTENCY_KEY = 'idempotencyKey';

    // Validation patterns used to recognise supported patient references.
    public const PATIENT_NAME_PATTERN = 'patient-name';
    public const PATIENT_EMAIL_PATTERN = 'patient-email';
    public const PATIENT_PHONE_PATTERN = 'patient-phone';
    public const PATIENT_POSTCODE_PATTERN = 'patient-postcode';
    public const PATIENT_REFERENCE_PATTERN = 'patient-reference';
    public const PATIENT_OVERFLOW_PATTERN = 'patient-overflow';

    // Routing regexes shared by the supported request classifiers.
    protected const ROUTE_PREFIX_REGEX = 'route-prefix';
    protected const ROUTE_SUFFIX_REGEX = 'route-suffix';
    protected const ROUTE_FALLBACK_REGEX = 'route-fallback';

    // Comparison patterns used to order the supported match strategies.
    protected const COMPARISON_ALPHA_PATTERN = 'alpha',
        COMPARISON_BETA_PATTERN = 'beta',
        COMPARISON_GAMMA_PATTERN = 'gamma',
        COMPARISON_DELTA_PATTERN = 'delta';
    protected const COMPARISON_EPSILON_PATTERN = 'epsilon',
        COMPARISON_ZETA_PATTERN = 'zeta';

    // Visibility patterns used by the public request contract.
    public const VISIBILITY_PUBLIC_PATTERN = 'public';
    protected const VISIBILITY_PROTECTED_PATTERN = 'protected';

    // Workflow values stored with each routing decision.
    protected const WORKFLOW_VALUE_ALPHA = 'alpha';
    protected const WORKFLOW_VALUE_BETA = 'beta';
    protected const WORKFLOW_VALUE_GAMMA = 'gamma';
    protected const WORKFLOW_VALUE_DELTA = 'delta';
    protected const WORKFLOW_VALUE_EPSILON = 'epsilon';
    protected const WORKFLOW_VALUE_ZETA = 'zeta';
    protected const WORKFLOW_VALUE_ETA = 'eta';
    protected const WORKFLOW_VALUE_THETA = 'theta';

    // Cache keys and patterns shared by the routing lookup.
    protected const MIXED_GROUP_ALPHA = 'alpha';
    protected const MIXED_GROUP_BETA = 'beta';
    protected const MIXED_GROUP_GAMMA = 'gamma';
    protected const MIXED_GROUP_DELTA = 'delta';
    protected const MIXED_GROUP_EPSILON = 'epsilon';
    protected const MIXED_GROUP_ZETA = 'zeta';
    protected const MIXED_GROUP_ETA = 'eta';

    // Primary matcher used for direct request classification.
    protected const SINGLE_MATCHER_PATTERN = 'single-matcher';
    protected const SINGLE_MATCHER_FOLLOWER = 'single-follower';

    // Reset patterns used before a dedicated fallback takes over.
    protected const RESET_GROUP_ALPHA_PATTERN = 'reset-alpha';
    protected const RESET_GROUP_BETA_PATTERN = 'reset-beta';
    // Dedicated fallback matcher used when the primary inputs are absent.
    protected const RESET_LOCAL_PATTERN = 'reset-local';
    protected const RESET_AFTER_LOCAL_PATTERN = 'reset-after-local';

    // Blank-boundary patterns used by the first parsing phase.
    protected const BLANK_GROUP_PATTERN = 'blank-group';

    protected const BLANK_AFTER_GROUP_PATTERN = 'blank-after-group';

    // Method-boundary patterns used before a computed label separates declarations.
    protected const METHOD_GROUP_PATTERN = 'method-group';
    /**
     * Build a visible label while separating two constant declaration runs.
     *
     * @return string - Stable boundary label used only by this fixture.
     */
    protected function patternBoundaryLabel(): string
    {
        $labelParts = ['pattern', 'boundary'];
        sort($labelParts);

        return implode('-', $labelParts);
    }
    protected const METHOD_AFTER_GROUP_PATTERN = 'method-after-group';

    // PHPDoc-boundary patterns used before an explicitly documented declaration.
    protected const PHPDOC_GROUP_PATTERN = 'phpdoc-group';
    /** Explicitly documented pattern that terminates inherited line-comment coverage. */
    protected const PHPDOC_DOCUMENTED_PATTERN = 'phpdoc-documented';
    protected const PHPDOC_AFTER_GROUP_PATTERN = 'phpdoc-after-group';

    /** Explicitly documents only the declaration on this line. */
    protected const TRAILING_COMMENT_OWNER = 'owner'; // Validation patterns used by this documented owner only.
    protected const TRAILING_COMMENT_FOLLOWER = 'follower';

    protected const SEARCH_RESULT_LIMIT = 25;
    protected const DATE_OF_BIRTH_PATTERN = 'date-of-birth';
}
