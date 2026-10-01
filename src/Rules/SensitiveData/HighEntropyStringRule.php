<?php

declare(strict_types=1);

namespace GruffPhp\Rules\SensitiveData;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\SourceTextRuleInterface;
use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;

/**
 * Reports long, random-looking literals that users should review for embedded credentials.
 *
 * Scans source text even when detailed syntax is unavailable and reports warnings at medium confidence.
 * Public formats and proven local autoload class slots stay quiet; provider-specific detectors keep their own findings.
 */
final readonly class HighEntropyStringRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for high-entropy string findings.
     */
    public const ID = 'sensitive-data.high-entropy-string';


    /**
     * PCRE's largest `{n,}` repeat count; a longer configured minimum is still enforced by the length check.
     */
    private const MAX_SCAN_LENGTH = 65535;

    /**
     * Any opening or closing PEM marker, so a block can end only at the next one.
     */
    private const PEM_MARKER_PATTERN = '/-----(BEGIN|END) ([A-Z0-9 ]+)-----/';

    /**
     * One line of a PEM body once its string quoting is stripped: base64, a PGP checksum or an armour header.
     */
    private const PEM_BODY_LINE_PATTERN = '/^(?:[A-Za-z0-9+\/]+={0,2}|=[A-Za-z0-9+\/]{4}|(?:Version|Comment|Hash|Charset|MessageID|Proc-Type|DEK-Info):.*)$/D';

    /**
     * Image extensions an HTML attribute may name. The list is closed: any other value stays with the entropy rule.
     */
    private const IMAGE_PATH_PATTERN = '/\.(?:png|jpe?g|gif|svg|webp|ico|avif)$/iD';

    /**
     * The text before a value's opening quote when the value is a whole src or href attribute: whitespace, the name and `=`.
     */
    private const IMAGE_ATTRIBUTE_PREFIX_PATTERN = '/(?:^|\s)(?:src|href)\s*=\s*$/iD';


    /**
     * Describes the high-entropy-string rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds (minimum length and entropy).
     */
    public function definition(): RuleDefinition
    {
        // Medium-confidence warnings ask users to confirm whether an unexplained random-looking value is a credential.
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
                    'shape' => 'Identifier, slug, and key literals: PHPCS sniff ids (PHPCompatibility.FunctionUse.NewFunctions.ldap_exop_syncFound), '
                        . 'class names (WPCOM_REST_API_V2_Endpoint_External_Media), BEM class names, package slugs (Automattic/i18n-check-webpack-plugin), and JSON/YAML-style field keys.',
                    'mitigation' => 'Exempt automatically only when the whole value matches a finite public format or the bounded family name grammar: '
                        . 'segments have at most 32 characters, ordinary or bounded compound casing and limited numeric runs; at least two segments '
                        . 'must contribute words of three or more letters and those letters must form a strict majority. Opaque tails invalidate the exception. '
                        . 'Quoted object/array keys remain distinct from values; a property name alone never exempts its value. Native autoload class '
                        . 'literals require a resolved built-in callable and an unconditional local class with its public static method.',
                ],
            ],
        );
    }

    /**
     * Reports each long, high-entropy literal that survives the false-positive exemptions.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context supplying the length and entropy thresholds.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Warnings to review; an empty list means no literal passed the rule's checks.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $settings = $ruleContext->settingsFor($this->definition());
        // A length below one would make every quoted character a candidate, so the floor never drops under it.
        $minLength        = max(1, (int) $settings->numericThreshold('minLength'));
        $entropyThreshold = (float) $settings->numericThreshold('entropy');

        // A literal shorter than 2^entropy cannot reach the configured bar, so it need not become a candidate.
        // This also prevents a short concatenation fragment from consuming a following secret's opening quote.
        $entropyFloor = (int) min(self::MAX_SCAN_LENGTH, ceil(2 ** max(0.0, $entropyThreshold)));
        $scanLength   = min(self::MAX_SCAN_LENGTH, max($minLength, $entropyFloor));

        // Match every quoted literal at least the scan length long, so lowering minLength widens the scan.
        preg_match_all('/(?<quote>["\'])(?<value>[A-Za-z0-9_+\/=.-]{' . $scanLength . ',})\k<quote>/', $analysisUnit->source, $matches, PREG_OFFSET_CAPTURE);

        $findings        = [];
        $commentRanges   = SecretScannerHelper::commentRanges($analysisUnit);
        $armoured        = $this->publicArmourSpans($analysisUnit->source);
        $publicLiteralOffsets = AutoloadCallableEvidence::classLiteralOffsets($analysisUnit) + $this->publicHelpLiteralOffsets($analysisUnit);
        // Review each matched value independently; no matches means there are no entropy warnings to return.
        foreach ($matches['value'] ?? [] as $match) {
            [$candidateSecret, $offset] = $match;
            // Only this exact proven callable slot or constant help-link fragment is public; equal values elsewhere still report.
            if (isset($publicLiteralOffsets[$offset])) {
                continue;
            }
            // A literal inside a comment is documentation, not a live value.
            if (SecretScannerHelper::isInsideComment($offset, $commentRanges)) {
                continue;
            }

            // A public PEM block's base64 body is certificate or public-key material, never a secret.
            if ($this->isInsideSpan($offset, $armoured)) {
                continue;
            }

            // Below the configured minimum length, a literal is too short to worry about.
            if (strlen($candidateSecret) < $minLength) {
                continue;
            }

            // Entropy in an object/array key belongs to the field name, not a stored secret.
            if ($this->isQuotedKeyLiteral($analysisUnit->source, $candidateSecret, $offset)) {
                continue;
            }

            // Exempt everything a more specific rule owns, or that is a benign path, identifier, dummy or existing page image.
            if (
                $this->shouldSkipKnownSecretPattern($candidateSecret)
                || EntropyPublicShape::accepts($candidateSecret)
                || SecretScannerHelper::isLikelyDummyValue($candidateSecret)
                || $this->isExistingImageAttributePath($analysisUnit, $ruleContext->projectRoot, $candidateSecret, $offset)
            ) {
                continue;
            }

            // Hex cannot be distinguished from a checksum here, so it stays quiet even when the user lowers the entropy bar.
            if (ctype_xdigit($candidateSecret)) {
                continue;
            }

            // Without a letter and a digit a literal is not credential-shaped: one character class clears the entropy bar
            // by construction, and a digit-free mix of cases is an identifier (FAMILY-CONTRACT section 12).
            if (!$this->hasLetterAndDigit($candidateSecret)) {
                continue;
            }

            // Below the configured entropy bar the literal reads as ordinary text; the bar is the only entropy gate.
            if (SecretScannerHelper::entropy($candidateSecret) < $entropyThreshold) {
                continue;
            }

            $displayMarker = SecretScannerHelper::fixedSecretMarker();
            $findings[]    = SecretScannerHelper::finding(
                analysisUnit:  $analysisUnit,
                ruleId:        self::ID,
                message:       sprintf('High-entropy string literal detected: %s.', $displayMarker),
                line:          SecretScannerHelper::lineNumberForOffset($analysisUnit->source, $offset),
                confidence:    Confidence::Medium,
                detector:      'high-entropy-string',
                displayMarker: $displayMarker,
                remediation:   'Confirm this is not a credential; move real secrets out of source. '
                    . 'Word-shaped identifiers and slugs are exempt automatically.',
            );
        }

        return $findings;
    }

    /**
     * Finds literal fragments belonging to a complete, constant public help URL.
     *
     * @param AnalysisUnit $unit - Source with optional syntax; missing or bounded syntax leaves every fragment scannable.
     * @return array<int, true> - Exact content offsets; an empty map grants no help-link exceptions.
     */
    private function publicHelpLiteralOffsets(AnalysisUnit $unit): array
    {
        // Large or malformed files may lack reliable syntax, so their strings keep the ordinary entropy checks.
        if ($unit->hasParseErrors() || $unit->isDeepScanBounded()) {
            return [];
        }
        $concatenations = NodeIndex::nodesOf($unit, Expr\BinaryOp\Concat::class);
        $nested = [];
        // Identify children from the AST itself; an inner public URL cannot vouch for an outer dynamic or opaque extension.
        foreach ($concatenations as $concatenation) {
            // Both sides may be concatenations, for example when a developer adds a suffix to a parenthesized link.
            foreach ([$concatenation->left, $concatenation->right] as $operand) {
                // Only the outermost concatenation may establish a complete value.
                if ($operand instanceof Expr\BinaryOp\Concat) {
                    $nested[spl_object_id($operand)] = true;
                }
            }
        }
        $offsets = [];
        // Assess each complete expression independently; equal text elsewhere keeps its own warning.
        foreach ($concatenations as $concatenation) {
            // A nested expression still has unexamined surrounding content and cannot grant an exception.
            if (isset($nested[spl_object_id($concatenation)])) {
                continue;
            }
            $parts = $this->constantStringParts($concatenation);
            // Dynamic content prevents proof of the full URL; an empty list supplies no source value to classify.
            if ($parts === null || $parts === []) {
                continue;
            }
            $completeUrl = implode('', array_map(static fn (String_ $part): string => $part->value, $parts));
            // Only a whole bounded help URL can exempt its exact literal fragments.
            if (!EntropyPublicShape::isHelpArticleUrl($completeUrl)) {
                continue;
            }
            // Each proven fragment maps back to its own opening quote, never to an equal value at another source location.
            foreach ($parts as $part) {
                $offsets[$part->getStartFilePos() + 1] = true;
            }
        }

        return $offsets;
    }

    /**
     * Collects a constant concatenation's strings in source order without executing the application.
     *
     * @param Expr $expression - Complete concatenation to inspect.
     * @return null|list<String_> - Literal parts; null means a variable, call or interpolation leaves the URL unknown.
     */
    private function constantStringParts(Expr $expression): ?array
    {
        $pending = [$expression];
        $parts = [];
        // Walk left to right so the assembled value matches the link a user would open.
        while ($pending !== []) {
            $part = array_pop($pending);
            // A literal contributes its parser-decoded value, including any escaped characters.
            if ($part instanceof String_) {
                $parts[] = $part;
            } elseif ($part instanceof Expr\BinaryOp\Concat) {
                // Push the right side first so the left side is read next.
                $pending[] = $part->right;
                $pending[] = $part->left;
            } else {
                // For example, a URL suffix loaded from configuration is not a proven constant.
                return null;
            }
        }

        return $parts;
    }

    /**
     * Reports whether a more specific detector already owns this literal.
     *
     * @param string $candidateSecret - Literal to classify; a known vendor prefix or token shape belongs to a dedicated rule.
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
     * Reports whether a quoted value is a whole HTML src or href value naming an existing image beside its page.
     *
     * Contract invariant: all three proofs are required - the attribute role, the closed image-extension list and an existing
     * regular file reached from the page's folder without leaving the project or passing through a symlink - so an attribute
     * name or a file suffix alone never silences a possible secret.
     *
     * @param AnalysisUnit $analysisUnit - Scanned file supplying the source text and the page's absolute path.
     * @param string       $projectRoot  - Absolute project root that the resolved image must stay inside.
     * @param string       $candidatePath - Candidate literal content without its quotes.
     * @param int          $offset       - Byte offset of the literal's first character, one past its opening quote.
     *
     * @return bool - True only when every proof holds; false keeps the entropy finding.
     */
    private function isExistingImageAttributePath(AnalysisUnit $analysisUnit, string $projectRoot, string $candidatePath, int $offset): bool
    {
        // Checks that the literal is relative and ends in one of the closed image extensions.
        if (str_starts_with($candidatePath, '/') || preg_match(self::IMAGE_PATH_PATTERN, $candidatePath) !== 1) {
            return false;
        }
        $quoteOffset = $offset - 1;
        $lineBreak   = strrpos(substr($analysisUnit->source, 0, $quoteOffset), "\n");
        $lineStart   = $lineBreak === false ? 0 : $lineBreak + 1;
        // Checks that the text before the opening quote on its line ends with a src or href attribute and its equals sign.
        if (preg_match(self::IMAGE_ATTRIBUTE_PREFIX_PATTERN, substr($analysisUnit->source, $lineStart, $quoteOffset - $lineStart)) !== 1) {
            return false;
        }

        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $page = str_replace('\\', '/', $analysisUnit->file->absolutePath);
        // A page outside the project has no project-relative folder to resolve the image from.
        if ($root === '' || !str_starts_with($page, $root . '/')) {
            return false;
        }
        $segments = [];
        $path = $root;
        // Check each traversed component before a parent step can discard it.
        foreach (explode('/', substr(dirname($page), strlen($root)) . '/' . $candidatePath) as $segment) {
            if (!is_dir($path)) {
                return false;
            }
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return false;
                }
                array_pop($segments);
                $path = dirname($path);
                continue;
            }
            $segments[] = $segment;
            $path .= '/' . $segment;
            if (is_link($path)) {
                return false;
            }
        }

        return is_file($path);
    }

    /**
     * Reports whether the literal is used as an object/array key, where entropy belongs to the field name.
     *
     * @param string $source          - Full source text being scanned.
     * @param string $candidateSecret - Candidate literal content without its quotes.
     * @param int    $offset          - Byte offset of the candidate content inside the source.
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
     * Requires both a letter and a digit before presenting a literal as a possible credential.
     * This family-wide floor keeps digit-free identifiers out of the user's entropy warnings.
     *
     * @param string $candidateSecret - Quoted literal being classified.
     *
     * @return bool - True when the literal holds both a letter and a digit.
     */
    private function hasLetterAndDigit(string $candidateSecret): bool
    {
        return strpbrk($candidateSecret, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ') !== false
            && strpbrk($candidateSecret, '0123456789') !== false;
    }

    /**
     * Locates public PEM material that users need not review as an entropy warning.
     * Only a matching next closing marker and a PEM-shaped body grant the exception; private-key material remains scannable.
     *
     * @param string $source - Full file source being scanned.
     *
     * @return list<array{int, int}> - Half-open public-block spans; an empty list leaves all source eligible for scanning.
     */
    private function publicArmourSpans(string $source): array
    {
        $spans = [];
        preg_match_all('/-----BEGIN ([A-Z0-9 ]+)-----/', $source, $openings, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        // Each opening marker can pair only with the next marker, which must close the same label.
        foreach ($openings as $opening) {
            [$marker, $start] = $opening[0];
            $label            = $opening[1][0];
            $bodyStart        = $start + strlen($marker);

            // A private key's block stays scannable: the key material there is the secret this rule exists for.
            if (str_contains($label, 'PRIVATE')) {
                continue;
            }

            // Another opening marker, a different label or no marker at all means these markers are not a block.
            if (preg_match(self::PEM_MARKER_PATTERN, $source, $closing, PREG_OFFSET_CAPTURE, $bodyStart) !== 1
                || $closing[1][0] !== 'END'
                || $closing[2][0] !== $label) {
                continue;
            }

            [$closingMarker, $closingOffset] = $closing[0];
            // Code, a placeholder or prose between the markers is not a PEM body, so nothing is exempted.
            if ($this->isPemShapedBody(substr($source, $bodyStart, $closingOffset - $bodyStart))) {
                $spans[] = [$start, $closingOffset + strlen($closingMarker)];
            }
        }

        return $spans;
    }

    /**
     * Checks a possible PEM body before hiding public material from the user's entropy warnings.
     * Real and escaped line breaks separate body lines; quoting and concatenation syntax do not count as body content.
     *
     * @param string $body - Text between matching markers; empty content contains no credential material to report.
     *
     * @return bool - False when any line is code, a placeholder or prose, or a pattern fails to run.
     */
    private function isPemShapedBody(string $body): bool
    {
        $segments = preg_split('/\n|\\\\[nrt]/', $body);

        // A pattern that fails to run cannot vouch for the body, so the markers are not treated as a block.
        if ($segments === false) {
            return false;
        }

        // Every body line must qualify; an armour header cannot excuse unrelated text later in the same literal.
        foreach ($segments as $segment) {
            $withoutOperators = preg_replace('/[ \t\r\f\x0B]+[+.]|[+.][ \t\r\f\x0B]+/', '', $segment);
            // A failed normalization leaves the body unproven, so it cannot hide possible credentials from the scan.
            $line = $withoutOperators === null
                ? null
                : preg_replace('/[ \t\r\f\x0B"\'`,;()\[\]{}#*\\\\]/', '', $withoutOperators);

            // A pattern that fails to run, or any other line, means the markers wrap code, a placeholder or prose.
            if ($line === null || ($line !== '' && preg_match(self::PEM_BODY_LINE_PATTERN, $line) !== 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks whether this candidate lies inside proven public PEM material and may skip an entropy warning.
     *
     * @param int                   $offset - Byte offset of the candidate literal.
     * @param list<array{int, int}> $spans  - Half-open public-block offsets; an empty list grants no exception.
     *
     * @return bool - True when a span contains the offset.
     */
    private function isInsideSpan(int $offset, array $spans): bool
    {
        // Check each proven public block; unrelated values beside those blocks still need secret review.
        foreach ($spans as [$start, $end]) {
            // The candidate sits between an opening marker and the end of its closing marker.
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }

        return false;
    }

}
