<?php

declare(strict_types=1);

namespace GruffPhp\Rules\SensitiveData;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Naming\IdentifierTokenizer;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\SourceTextRuleInterface;

/**
 * Flags a long, high-entropy string literal that may be an embedded secret, so the user can confirm it and
 * move real credentials out of source.
 *
 * A source-text rule with a deep false-positive defence: before scoring entropy it exempts values another
 * detector owns (AWS/GitHub/Slack/JWT shapes), file paths and route URLs, gruff config paths, word-shaped
 * identifiers and slugs, known character-set alphabets, framework references, and public clinical-code
 * metadata. Only what survives and clears the entropy threshold reports. Warning, medium confidence.
 */
final readonly class HighEntropyStringRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for high-entropy string findings.
     */
    public const ID = 'sensitive-data.high-entropy-string';

    /**
     * Minimum separator-delimited segments before a literal can read as an identifier or slug.
     */
    private const IDENTIFIER_MIN_SEGMENTS = 2;

    /**
     * Minimum length for a pure-alphabetic segment to count as a dictionary-like word.
     */
    private const WORD_SEGMENT_MIN_LENGTH = 3;

    /**
     * Any single non-word segment at or above this length reads as the random tail of a prefixed
     * credential (`config_prod_<random>`, `sk_live_`-style keys), so the identifier exemption is refused
     * outright regardless of how the character census lands.
     */
    private const RANDOM_SEGMENT_REFUSAL_LENGTH = 16;

    /**
     * Strict-majority ratio for the character-weighted word census: alpha-word characters must exceed
     * this fraction of all alphanumeric characters across the segments for the exemption to hold.
     */
    private const WORD_CHARACTER_MAJORITY_RATIO = 0.5;

    /**
     * Ordered alphabets used by parsers/generators; these are keyspaces, not secret material.
     *
     * @var array<string, true>
     */
    private const KNOWN_CHARACTER_SET_LITERALS = [
        '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'  => true,
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'                           => true,
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_' => true,
        'abcdefghijklmnopqrstuvwxyz0123456789-_'                          => true,
    ];

    /**
     * Describes the high-entropy-string rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds (minimum length and entropy).
     */
    public function definition(): RuleDefinition
    {
        // Warning at medium confidence: entropy catches real secrets but also rule-path noise,
        // so this advises rather than blocks.
        return new RuleDefinition(
            id:                self::ID,
            name:              'High entropy string',
            pillar:            Pillar::SensitiveData,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Warning,
            confidence:        Confidence::Medium,
            defaultThresholds: [
                'minLength' => 32,
                'entropy' => 4.2,
            ],
            falsePositiveShapes: [
                [
                    'shape'      => 'Identifier, slug, and key literals: PHPCS sniff ids (PHPCompatibility.FunctionUse.NewFunctions.ldap_exop_syncFound), '
                        . 'class names (WPCOM_REST_API_V2_Endpoint_External_Media), BEM class names, package slugs (Automattic/i18n-check-webpack-plugin), and JSON/YAML-style field keys.',
                    'mitigation' => 'Exempt automatically: a literal with no +/= that splits on [/._-] runs into two or more alphanumeric segments '
                        . 'reads as an identifier only when alphabetic words of three or more characters supply strictly more than half of all '
                        . 'alphanumeric characters and no single non-word segment reaches 16 characters. Prefixed keys (config_prod_<random>), '
                        . 'slugs with hex tails, and dot-joined JWT/JWE tokens keep flagging because their random runs dominate the character census. '
                        . 'Quoted object/array keys are skipped because committed secrets live in values, not identifier keys.',
                ],
            ],
        );
    }

    /**
     * Reports each long, high-entropy literal that survives the false-positive exemptions.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context supplying the length and entropy thresholds.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings for suspicious high-entropy literals.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $settings         = $ruleContext->settingsFor($this->definition());
        $minLength        = (int) $settings->numericThreshold('minLength');
        $entropyThreshold = (float) $settings->numericThreshold('entropy');

        // Match every long quoted literal in the source, capturing its value and offset.
        preg_match_all('/["\'](?<value>[A-Za-z0-9_+\/=.-]{32,})["\']/', $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings      = [];
        $commentRanges = SecretScannerHelper::commentRanges($analysisUnit);
        // Weigh each candidate literal the scan found.
        foreach ($matches['value'] as $match) {
            [$candidateSecret, $offset] = $match;
            // A literal inside a comment is documentation, not a live value.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            // Below the configured minimum length, a literal is too short to worry about.
            if (strlen($candidateSecret) < $minLength) {
                continue;
            }

            $line = $this->lineText($analysisUnit->source, SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset));
            // Entropy in an object/array key belongs to the field name, not a stored secret.
            if ($this->isQuotedKeyLiteral($analysisUnit->source, $candidateSecret, $offset)) {
                continue;
            }

            // Exempt everything a more specific rule owns, or that is a benign path, identifier, or dummy.
            if (
                $this->shouldSkipKnownSecretPattern($candidateSecret)
                || $this->isPathLikeLiteral($candidateSecret)
                || $this->isGruffConfigPathLiteral($candidateSecret)
                || $this->isIdentifierOrSlugLiteral($candidateSecret)
                || $this->isKnownCharacterSetLiteral($candidateSecret)
                || $this->isFrameworkIdentifierReference($candidateSecret, $line)
                || SecretScannerHelper::isLikelyDummyValue($candidateSecret)
            ) {
                continue;
            }

            // Public clinical-code identifiers mimic entropy but are standards metadata, not secrets.
            if ($this->isMedicalStandardsMetadata($candidateSecret, $line)) {
                continue;
            }

            $entropy = SecretScannerHelper::entropy($candidateSecret);
            // Below the entropy bar (and not a long hex digest) the literal reads as ordinary text.
            if ($entropy < $entropyThreshold && !(strlen($candidateSecret) >= 64 && ctype_xdigit($candidateSecret))) {
                continue;
            }

            $preview    = SecretScannerHelper::redactedPreview($candidateSecret);
            $findings[] = SecretScannerHelper::finding(
                analysisUnit: $analysisUnit,
                ruleId:       self::ID,
                message:      sprintf('High-entropy string literal detected: %s.', $preview),
                line:         SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:   Confidence::Medium,
                detector:     'high-entropy-string',
                preview:      $preview,
                remediation:  'Confirm this is not a credential; move real secrets out of source. '
                    . 'Word-shaped identifiers and slugs are exempt automatically.',
            );
        }

        return $findings;
    }

    /**
     * Reports whether a more specific detector already owns this literal.
     *
     * @param string $candidateSecret - Literal under test; a known vendor prefix or token shape means a dedicated
     *                                rule owns it.
     *
     * @return bool - True when another rule should handle the literal.
     */
    private function shouldSkipKnownSecretPattern(string $candidateSecret): bool
    {
        // Skip literals a more specific detector already covers, so the same secret is not double-reported here.
        return str_starts_with($candidateSecret, 'AKIA')
            || str_starts_with($candidateSecret, 'ASIA')
            || str_starts_with($candidateSecret, 'sk_live_')
            || str_starts_with($candidateSecret, 'sk-proj-')
            || str_starts_with($candidateSecret, 'sk-ant-')
            || str_starts_with($candidateSecret, 'ghp_')
            || str_starts_with($candidateSecret, 'gho_')
            || str_starts_with($candidateSecret, 'ghr_')
            || str_starts_with($candidateSecret, 'ghs_')
            || str_starts_with($candidateSecret, 'ghu_')
            || str_starts_with($candidateSecret, 'github_pat_')
            || str_starts_with($candidateSecret, 'glpat-')
            || str_starts_with($candidateSecret, 'npm_')
            || str_starts_with($candidateSecret, 'AIza')
            || str_starts_with($candidateSecret, 'xox')
            || str_starts_with($candidateSecret, 'https://hooks.slack.com/services/')
            || JwtTokenRule::matchesJwtShape($candidateSecret)
            || (strlen($candidateSecret) <= 48 && ctype_alpha($candidateSecret));
    }

    /**
     * Reports whether a literal looks like a file path, which trips the length heuristic but holds no secret.
     *
     * @param string $candidateSecret - Literal under test; file paths and route URLs trip the length heuristic but
     *                                hold no secret.
     *
     * @return bool - True when the literal looks like a file path.
     */
    private function isPathLikeLiteral(string $candidateSecret): bool
    {
        if (!str_contains($candidateSecret, '/') && !str_contains($candidateSecret, '\\')) {
            // No directory separator at all means it cannot be a path, so it stays eligible as a secret.
            return false;
        }

        if ($this->isUrlOrRoutePathLiteral($candidateSecret)) {
            // A URL or route path is benign even without a file extension, so exempt it before the extension check.
            return true;
        }

        // Recognize common source/config/documentation/script file extensions in path-like literals.
        return preg_match('/\\.(?:php|inc|json|xml|neon|ya?ml|txt|md|stub|sh)$/i', $candidateSecret) === 1;
    }

    /**
     * Reports whether a literal is a public URL or route path, long because of slugs rather than entropy.
     *
     * @param string $candidateSecret - Literal under test; a long public URL or route path otherwise reads as entropy.
     *
     * @return bool - True when the literal is shaped like a public URL path.
     */
    private function isUrlOrRoutePathLiteral(string $candidateSecret): bool
    {
        if (str_starts_with($candidateSecret, 'https://hooks.slack.com/services/')) {
            // Slack webhook URLs are genuine secrets despite their URL shape, so never exempt them as routes.
            return false;
        }

        // Match URI schemes so absolute URLs can be normalized before path checks.
        $hasScheme = preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidateSecret) === 1;
        if (!$hasScheme && !str_starts_with($candidateSecret, '/') && !str_starts_with($candidateSecret, './') && !str_starts_with($candidateSecret, '../')) {
            // Without a scheme or a leading path marker there is no route to inspect, so treat it as a possible secret.
            return false;
        }

        $withoutScheme = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $candidateSecret);
        if (!is_string($withoutScheme)) {
            // A regex engine error yields null; fail closed so a malformed strip is not mistaken for a clean route.
            return false;
        }

        $slashOffset = strpos($withoutScheme, '/');
        $path        = $slashOffset === false ? $withoutScheme : substr($withoutScheme, $slashOffset);
        if ($path === '' || $path[0] !== '/') {
            // No rooted path component means there is nothing route-shaped to whitelist.
            return false;
        }

        if (str_contains($path, '?') || str_contains($path, '#')) {
            // Query or fragment markers signal an opaque token tail, not a clean route, so do not exempt it.
            return false;
        }

        // Match public route/path characters.
        $hasPublicPathShape = preg_match('#^/[A-Za-z0-9._~/%:-]+$#', $path) === 1;
        // Match natural-language path segments rather than opaque tokens.
        $hasAlphabeticSegment = preg_match('/[A-Za-z]{3,}/', $path) === 1;
        // Match token separators that are common in credentials but not route paths.
        $hasTokenSeparator = preg_match('/[+=]/', $path) === 1;

        // Treat as a route only with a real path shape and word-like segments and no credential separators.
        return $hasPublicPathShape && $hasAlphabeticSegment && !$hasTokenSeparator;
    }

    /**
     * Reports whether a literal is a gruff config path (`rules.<id>....`) rather than secret material.
     *
     * @param string $candidateSecret - Literal under test; dotted config keys can look high entropy but are public metadata.
     *
     * @return bool - true when the literal is a gruff configuration path rather than secret material
     */
    private function isGruffConfigPathLiteral(string $candidateSecret): bool
    {
        if (
            !str_starts_with($candidateSecret, 'rules.')
            && !str_starts_with($candidateSecret, 'paths.')
            && !str_starts_with($candidateSecret, 'allowlists.')
            && !str_starts_with($candidateSecret, 'selection.')
        ) {
            // Without a known config root, the literal is not a gruff config path and stays eligible for scanning.
            return false;
        }

        // Match known config roots followed by dotted path segments; values, URLs, and credentials do not use this shape.
        return preg_match('/^(?:rules|paths|allowlists|selection)\.[A-Za-z0-9_.-]+$/', $candidateSecret) === 1;
    }

    /**
     * Reports whether a literal is an identifier or slug that decomposes into dictionary-like word segments.
     *
     * These literals (sniff ids, class names, package slugs) read as high entropy but split into word
     * segments no encoded credential exhibits.
     *
     * @param string $candidateSecret - Literal under test; dotted/underscored identifiers and separator-joined slugs
     *                                trip the entropy gate without holding secret material.
     *
     * @return bool - True when the literal is an identifier or slug rather than secret material.
     */
    private function isIdentifierOrSlugLiteral(string $candidateSecret): bool
    {
        if (str_contains($candidateSecret, '+') || str_contains($candidateSecret, '=')) {
            // Padding and token separators appear in encoded credentials but never in identifiers or slugs.
            return false;
        }

        // Match the dotted-identifier shape PHPCS sniff ids use: letter-led segments joined by two or more dots.
        $hasDottedIdentifierShape = preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*){2,}$/', $candidateSecret) === 1;
        // Match the underscore-identifier shape class names such as WPCOM_REST_API_V2_Endpoint_External_Media use.
        $hasUnderscoreIdentifierShape = preg_match('/^[A-Za-z][A-Za-z0-9]*(?:_[A-Za-z0-9]+)+$/', $candidateSecret) === 1;
        // Match the slug shape package paths and BEM class names use: alphanumeric segments joined by one or more [/._-] separators.
        $hasSlugShape = preg_match('#^[A-Za-z0-9]+(?:[/._-]+[A-Za-z0-9]+)+$#', $candidateSecret) === 1;
        if (!$hasDottedIdentifierShape && !$hasUnderscoreIdentifierShape && !$hasSlugShape) {
            // Anything outside the three identifier/slug shapes stays eligible for entropy scanning.
            return false;
        }

        // The shape alone is not load-bearing: dot-joined JWT/JWE tokens and base64url material with an
        // underscore satisfy the regexes too, so every shape must also pass the word-segment decomposition.
        return $this->hasMostlyAlphaWordSegments($candidateSecret);
    }

    /**
     * Requires the word-segment decomposition that separates identifiers from encoded credentials.
     *
     * Split on `[/._-]`, every segment alphanumeric, no non-word segment long enough to be a random
     * credential tail, and a character-weighted strict majority of alpha-word characters. A segment-count
     * census would let two short dictionary words outvote one long random run (`config_prod_<32-char
     * tail>`), so the census weighs characters, not segments, and a single long non-word segment refuses
     * the exemption outright.
     *
     * @param string $candidateSecret - Literal already matching an identifier/slug shape.
     *
     * @return bool - True when alpha-word characters dominate and no segment reads as a random credential tail.
     */
    private function hasMostlyAlphaWordSegments(string $candidateSecret): bool
    {
        // Split the literal into its separator-delimited segments for the word-shape census.
        $segments = preg_split('#[/._-]+#', $candidateSecret, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($segments) || count($segments) < self::IDENTIFIER_MIN_SEGMENTS) {
            // A regex engine error or an unbroken token (no separators) is not an identifier compound; fail
            // closed so single-run secrets such as 64-char hex digests stay eligible for entropy scanning.
            return false;
        }

        $wordCharacterCount  = 0;
        $totalCharacterCount = 0;
        // Weigh each segment's contribution to the word-character census.
        foreach ($segments as $segment) {
            if (!ctype_alnum($segment)) {
                // A non-alphanumeric segment means the literal is not a clean identifier compound.
                return false;
            }

            $segmentLength             = strlen($segment);
            $segmentWordCharacterCount = $this->wordCharacterCountForSegment($segment);
            if ($segmentLength >= self::RANDOM_SEGMENT_REFUSAL_LENGTH && $segmentWordCharacterCount <= $segmentLength * self::WORD_CHARACTER_MAJORITY_RATIO) {
                // One long non-word run is exactly the random tail of a prefixed key (`secret-key-<hex>`,
                // `myapp/prod-keys/<hex>`); no amount of word prefix can make that an identifier.
                return false;
            }

            $totalCharacterCount += $segmentLength;
            $wordCharacterCount  += $segmentWordCharacterCount;
        }

        // The census is character-weighted: alpha-word characters must strictly outnumber the non-word rest,
        // so WPCOM_REST_API_V2_Endpoint_External_Media (33 of 35 chars in words) passes while
        // config_prod_<32-char tail> (10 of 42) fails even though its word segments outnumber the tail.
        return $wordCharacterCount > $totalCharacterCount * self::WORD_CHARACTER_MAJORITY_RATIO;
    }

    /**
     * Counts the dictionary-like alpha characters inside one identifier segment.
     *
     * @param string $segment - One separator-free alphanumeric segment.
     *
     * @return int - Number of characters belonging to alpha words of at least WORD_SEGMENT_MIN_LENGTH.
     */
    private function wordCharacterCountForSegment(string $segment): int
    {
        // A whole-alpha segment of real length counts entirely as word characters.
        if (strlen($segment) >= self::WORD_SEGMENT_MIN_LENGTH && ctype_alpha($segment)) {
            return strlen($segment);
        }

        $wordCharacterCount = 0;
        // Otherwise split the segment and count only its dictionary-like word tokens.
        foreach ((new IdentifierTokenizer())->tokenize($segment) as $token) {
            // Count a token only when it is a real alphabetic word of sufficient length.
            if (strlen($token) >= self::WORD_SEGMENT_MIN_LENGTH && ctype_alpha($token)) {
                $wordCharacterCount += strlen($token);
            }
        }

        return $wordCharacterCount;
    }

    /**
     * Reports whether the literal is used as an object/array key, where entropy belongs to the field name.
     *
     * @param string $source - Full source text being scanned.
     * @param string $candidateSecret - Candidate literal content without its quotes.
     * @param int    $offset - Byte offset of the candidate content inside the source.
     *
     * @return bool - True when the literal is immediately used as an object/array key.
     */
    private function isQuotedKeyLiteral(string $source, string $candidateSecret, int $offset): bool
    {
        $tail = substr($source, $offset + strlen($candidateSecret));

        // Match the closing quote followed by object/array key syntax.
        return preg_match('/^[\'"]\s*(?::|=>)/', $tail) === 1;
    }

    /**
     * Reports whether the literal is a known parser/generator keyspace alphabet that is intentionally public.
     *
     * @param string $candidateSecret - Candidate literal under test.
     *
     * @return bool - True when the literal is a known ordered character set.
     */
    private function isKnownCharacterSetLiteral(string $candidateSecret): bool
    {
        return isset(self::KNOWN_CHARACTER_SET_LITERALS[$candidateSecret]);
    }

    /**
     * Reports whether the literal is a PHP identifier used as framework metadata, not a secret value.
     *
     * @param string $candidateSecret - Candidate literal under test.
     * @param string $line - Source line carrying the literal.
     *
     * @return bool - True when the literal is a PHP identifier used as framework metadata.
     */
    private function isFrameworkIdentifierReference(string $candidateSecret, string $line): bool
    {
        // Match a plain PHP identifier rather than an opaque token.
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $candidateSecret) === 1
            // Match PHPUnit/Pest-style data-provider metadata references.
            && preg_match('/\bDataProvider\s*\(/', $line) === 1;
    }

    /**
     * Reports whether the literal is public clinical-code metadata (HL7/OID) rather than a secret.
     *
     * @param string $candidateSecret - Long token under test; clinical code systems use IDs that mimic secret entropy.
     * @param string $line - Source line of the literal; the surrounding field name is what marks it
     *                                as metadata.
     *
     * @return bool - True when the candidate is medical terminology metadata.
     */
    private function isMedicalStandardsMetadata(string $candidateSecret, string $line): bool
    {
        // Match clinical terminology field names that carry public standards metadata.
        if (!preg_match('/(?:CodeSystem|ConceptCode|HL7|OID|ValueSet)/i', $line)) {
            // Without a clinical field name nearby the token is not standards metadata, so leave it for entropy checks.
            return false;
        }

        // Match HL7 value-set codes such as PHVS_ObservationInterpretation_HL7_V3.
        if (preg_match('/^(?:PH|PHVS)_[A-Za-z0-9_]+_HL7_V\d+$/', $candidateSecret) === 1) {
            // A recognised HL7 value-set code is public metadata, never a credential.
            return true;
        }

        // Match dotted OID identifiers used by medical terminology systems.
        if (preg_match('/^\d+(?:\.\d+){3,}$/', $candidateSecret) === 1) {
            // A dotted OID is a public terminology identifier, never a credential.
            return true;
        }

        // Match field names that explicitly identify HL7 code metadata.
        $hasHl7MetadataField = preg_match('/(?:CodeSystemCode|HL7Table|ValueSetCode)/i', $line) === 1;

        // Remaining HL7-bearing tokens count as metadata only when an explicit HL7 code field names them.
        return str_contains($candidateSecret, 'HL7') && $hasHl7MetadataField;
    }

    /**
     * Returns the source text for a 1-based line number, or empty when unavailable.
     *
     * @param string $source - Full file source the literal was matched in.
     * @param int    $lineNumber - 1-based line number of the literal, as reported by the offset-to-line helper.
     *
     * @return string - Line text, or an empty string when unavailable.
     */
    private function lineText(string $source, int $lineNumber): string
    {
        $lines = explode("\n", $source);

        // Hand back the literal's own line for the metadata field-name check;
        return $lines[$lineNumber - 1] ?? '';
    }
}
