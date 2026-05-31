<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Modernisation;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;

/**
 * Detects PHPDoc `mixed` usage that should be narrowed.
 */
final readonly class PhpDocMixedOveruseRule implements RuleInterface
{
    /**
     * Stable identifier for the PHPDoc mixed-overuse rule.
     */
    public const ID = 'modernisation.phpdoc-mixed-overuse';

    /**
     * Parameter tag names scanned for standalone `mixed` usage.
     */
    private const PARAM_TAGS = ['param', 'phpstan-param', 'psalm-param'];

    /**
     * Return tag names scanned for standalone `mixed` usage.
     */
    private const RETURN_TAGS = ['return', 'phpstan-return', 'psalm-return'];

    /**
     * Variable tag names scanned for standalone `mixed` usage.
     */
    private const VAR_TAGS = ['var', 'phpstan-var', 'psalm-var'];

    /**
     * Property tag names scanned for standalone `mixed` usage.
     */
    private const PROPERTY_TAGS = [
        'property',
        'property-read',
        'property-write',
        'phpstan-property',
        'psalm-property',
    ];

    /**
     * Type alias tag names scanned for standalone `mixed` usage.
     */
    private const TYPE_ALIAS_TAGS = ['phpstan-type', 'phpstan-import-type'];

    /**
     * Describe the rule for the registry and reports.
     *
     * @return RuleDefinition
     */
    public function definition(): RuleDefinition
    {
        // Hand back this rule's fixed identity and defaults for the registry and reports.
        return new RuleDefinition(
            id:              self::ID,
            name:            'PHPDoc mixed overuse',
            pillar:          Pillar::Modernisation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            description: 'Flags PHPDoc @param/@return/@var/@property tags using mixed where a narrower type would carry more meaning. Unstructured array bags and precise array{...} envelope shapes are exempt.',
            falsePositiveShapes: [
                [
                    'shape' => 'JSON-boundary helpers consuming json_decode output where every top-level shape is possible.',
                    'mitigation' => 'Narrow @param/@return to `array|bool|float|int|string|null` - the supertype of any top-level decoded value.',
                ],
                [
                    'shape' => 'Envelope shapes with mixed leaves (FHIR resources, LLM tool-call args).',
                    'mitigation' => 'Use `array{key: type, ...}` syntax with at least one concrete sibling field; precise envelopes are exempted.',
                ],
            ],
        );
    }

    /**
     * Detect PHPDoc tags that use `mixed` where a narrower type would carry more meaning.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding>
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast bail: nothing to find when no `mixed` literal appears in the
        // file's source text.
        if (!str_contains($analysisUnit->source, 'mixed')) {
            // No `mixed` token anywhere means no tag can match; skip the AST walk entirely.
            return [];
        }

        $definition = $this->definition();
        $findings   = [];

        $targets = NodeIndex::nodesOfAny(
            $analysisUnit,
            [ClassMethod::class, Function_::class, Property::class, ClassConst::class],
        );

        foreach ($targets as $node) {
            $doc = $node->getDocComment();
            if (!$doc instanceof Doc) {
                continue;
            }

            $symbol = $this->resolveSymbol($node);

            foreach ($this->extractTagBlocks($doc) as $block) {
                $tagKind = $block['tag'];
                if (!$this->isScannedTag($tagKind)) {
                    continue;
                }

                $analysis = $this->classifyMixedInBody($block['body'], in_array($tagKind, self::TYPE_ALIAS_TAGS, true));
                if (!$analysis['hasMixed']) {
                    continue;
                }

                if ($this->isUnstructuredArrayBagType($block['body'])) {
                    continue;
                }

                if ($this->isPreciseArrayShape($block['body'])) {
                    continue;
                }

                $paramName = in_array($tagKind, self::PARAM_TAGS, true)
                    ? $this->extractParamName($block['body'])
                    : null;

                if ($analysis['isStandalone'] && $this->hasSignatureBroadTypeCoverage($node, $tagKind, $paramName)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:  $definition->id,
                    message: sprintf(
                        '%s has @%s using mixed; prefer a narrower PHPDoc type.',
                        $symbol,
                        $tagKind,
                    ),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $block['line'],
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $symbol,
                    remediation: 'Narrow the PHPDoc type to match what the function actually accepts. For JSON-boundary helpers use `array|bool|float|int|string|null`. For envelope shapes where the leaf is genuinely heterogeneous (e.g. FHIR resources, LLM tool-call args), name the envelope precisely with `array{...}` syntax — precise shapes are exempted by the rule. When only one input shape is meaningful, narrow to that concrete type (`?string`, `int|float|null`, a named class).',
                    metadata:    [
                        'tagKind' => $tagKind,
                        'paramName' => $paramName,
                        'snippet' => trim($block['body']),
                    ],
                );
            }
        }

        // Hand back every mixed-overuse finding gathered across the unit's documented nodes.
        return $findings;
    }

    /**
     * Detect whether the tag is one this rule examines (param / return / var / property / type-alias variants).
     *
     * @param string $tagName Lower-cased PHPDoc tag name (without the leading `@`) to classify.
     * @return bool
     */
    private function isScannedTag(string $tagName): bool
    {
        // True when the tag belongs to any family that can carry a type worth narrowing.
        return in_array($tagName, self::PARAM_TAGS, true)
            || in_array($tagName, self::RETURN_TAGS, true)
            || in_array($tagName, self::VAR_TAGS, true)
            || in_array($tagName, self::PROPERTY_TAGS, true)
            || in_array($tagName, self::TYPE_ALIAS_TAGS, true);
    }

    /**
     * Extract PHPDoc tag bodies with their source line numbers.
     *
     * @param Doc $doc Docblock attached to the node under inspection; multi-line tag bodies are joined.
     * @return list<array{tag: string, body: string, line: int}>
     */
    private function extractTagBlocks(Doc $doc): array
    {
        $startLine = $doc->getStartLine();
        $lines     = preg_split('/\R/', $doc->getText()) ?: [];

        $blocks  = [];
        $current = null;

        foreach ($lines as $offset => $rawLine) {
            $stripped = $this->stripDocPrefix($rawLine);

            // Split a PHPDoc line into the tag name and remaining tag body.
            if (preg_match('/^@([A-Za-z][A-Za-z0-9_-]*)\b\s*(.*)$/', $stripped, $matches) === 1) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = [
                    'tag' => strtolower($matches[1]),
                    'body' => $matches[2],
                    'line' => $startLine + $offset,
                ];
                continue;
            }

            if ($current !== null) {
                $current['body'] .= "\n" . $stripped;
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        // One entry per docblock tag, in source order, each with its tag name, body, and line.
        return $blocks;
    }

    /**
     * Strip the leading `/**`, trailing `*​/`, and per-line `*` characters from a docblock line.
     *
     * @param string $line One raw physical line of the docblock, still carrying its `*` framing.
     * @return string The line's textual content without the docblock framing.
     */
    private function stripDocPrefix(string $line): string
    {
        $trimmed = ltrim($line);
        $trimmed = preg_replace('/^\/\*+/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\*+\/$/', '', $trimmed) ?? $trimmed;
        $trimmed = ltrim($trimmed);
        $trimmed = preg_replace('/^\*+\s?/', '', $trimmed) ?? $trimmed;

        // The line's bare textual content, with opening, closing, and per-line `*` framing removed.
        return $trimmed;
    }

    /**
     * Detect whether a PHPDoc tag contains a standalone mixed type.
     *
     * @param string $body        Tag body after the tag name, e.g. the text following `@param`.
     * @param bool   $isTypeAlias True when the body is a `phpstan-type`/`import-type` alias, so the alias
     *                            name is skipped before the aliased type is parsed.
     * @return array{hasMixed: bool, isStandalone: bool}
     */
    private function classifyMixedInBody(string $body, bool $isTypeAlias): array
    {
        $type = $isTypeAlias
            ? $this->extractTypeAliasExpression($body)
            : $this->extractTypeExpression($body);

        // Find standalone `mixed` tokens without matching substrings inside class names.
        if ($type === null || preg_match('/(?<![\\\\\w])mixed(?!\w)/i', $type) !== 1) {
            // No `mixed` token to narrow; report neither presence nor a standalone match.
            return ['hasMixed' => false, 'isStandalone' => false];
        }

        $standalone = strcasecmp($type, 'mixed') === 0;

        // `mixed` is present; standalone is true only when the whole type is exactly `mixed`.
        return ['hasMixed' => true, 'isStandalone' => $standalone];
    }

    /**
     * Detect unstructured decoded/config payload bags where mixed is the honest boundary type.
     *
     * @param string $body Tag body whose leading type expression is tested for an array/list bag.
     * @return bool True for array/list bags whose leaves are unknown payload values.
     */
    private function isUnstructuredArrayBagType(string $body): bool
    {
        $type = $this->extractTypeExpression($body);
        if ($type === null) {
            // An empty body carries no type, so it cannot be an unstructured bag.
            return false;
        }

        $type = $this->stripTopLevelNullUnion(strtolower(preg_replace('/\s+/', '', $type) ?? $type));

        // Defer the bag decision to the shared predicate once the type is normalized and de-nulled.
        return $this->isArrayBagType($type);
    }

    /**
     * @param string $type Whitespace-stripped, lowercased type expression to test for an array/list generic.
     * @return bool True when the normalized type is an array/list bag with mixed leaves.
     */
    private function isArrayBagType(string $type): bool
    {
        // Capture the value side of array-key/string/int keyed generic array types.
        if (preg_match('/^array<(?:array-key|string|int),(.+)>$/', $type, $matches) === 1) {
            // Keyed array: the bag verdict rests on whatever its value type resolves to.
            return $this->isArrayBagValueType($matches[1]);
        }

        // Capture the element type from list generics.
        if (preg_match('/^list<(.+)>$/', $type, $matches) === 1) {
            // List generic: the bag verdict rests on its element type.
            return $this->isArrayBagValueType($matches[1]);
        }

        // Anything that is not a keyed-array or list generic is not an unstructured bag.
        return false;
    }

    /**
     * @param string $type Value side of an array/list generic; recursed when itself a nested bag.
     * @return bool True when an array/list bag value type ends in mixed payload leaves.
     */
    private function isArrayBagValueType(string $type): bool
    {
        if ($type === 'mixed') {
            // A `mixed` leaf is the unstructured bottom this exemption is looking for.
            return true;
        }

        // Otherwise the value is itself a generic; recurse so nested bags still qualify.
        return $this->isArrayBagType($type);
    }

    /**
     * Detect PHPStan/Psalm `array{...}` shapes that carry at least one field whose
     * type is not `mixed`. The nested mixed in that case describes a genuinely
     * heterogeneous leaf (e.g. JSON envelope payload) rather than type sloppiness.
     * An `array{value: mixed}` shape with no concrete sibling field is NOT
     * precise and still fires.
     *
     * @param string $body Tag body whose leading type is inspected for an `array{...}` shape.
     * @return bool True when the type is a precise envelope shape.
     */
    private function isPreciseArrayShape(string $body): bool
    {
        $type = $this->extractTypeExpression($body);
        if ($type === null) {
            // No type at all cannot be a precise shape.
            return false;
        }

        $type = preg_replace('/\s+/', '', $type) ?? $type;
        if (!preg_match('/^array\{(.*)\}$/i', $type, $matches)) {
            // Not `array{...}` syntax, so the shape-precision exemption does not apply.
            return false;
        }

        $inner = $matches[1];
        if ($inner === '') {
            // An empty `array{}` names no fields, so it is not a precise envelope.
            return false;
        }

        foreach ($this->splitTopLevelComma($inner) as $pair) {
            $colonIndex = $this->topLevelColonIndex($pair);
            if ($colonIndex === null) {
                continue;
            }

            $fieldType = trim(substr($pair, $colonIndex + 1));
            if ($fieldType !== '' && strcasecmp($fieldType, 'mixed') !== 0) {
                // One concrete (non-mixed) sibling field is enough to call the envelope precise.
                return true;
            }
        }

        // Every field was mixed (or untyped), so the shape adds no precision over a bare bag.
        return false;
    }

    /**
     * Split a string on top-level commas, ignoring commas nested inside `<>{}()[]`.
     *
     * @param string $body Inner shape text (between `array{` and `}`) to split into field pairs.
     * @return list<string>
     */
    private function splitTopLevelComma(string $body): array
    {
        $segments = [];
        $segment  = '';
        $depth    = 0;
        $length   = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($char === '<' || $char === '{' || $char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === '>' || $char === '}' || $char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            }

            if ($depth === 0 && $char === ',') {
                $segments[] = $segment;
                $segment    = '';
                continue;
            }

            $segment .= $char;
        }

        if ($segment !== '') {
            $segments[] = $segment;
        }

        // One segment per top-level field, with bracket-nested commas kept intact inside each.
        return $segments;
    }

    /**
     * Return the index of the first top-level colon in a shape pair, or null when none exists.
     *
     * @param string $pair A single `key: type` shape field, possibly nesting `<>{}()[]` in the type.
     */
    private function topLevelColonIndex(string $pair): ?int
    {
        $depth  = 0;
        $length = strlen($pair);

        for ($i = 0; $i < $length; $i++) {
            $char = $pair[$i];

            if ($char === '<' || $char === '{' || $char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === '>' || $char === '}' || $char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ':' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Extract the leading type expression from a tag body, balancing generics / arrays / shapes.
     *
     * @param string $body Tag body; the type is read up to the first top-level whitespace.
     * @return string|null The type expression, or null when the body is empty.
     */
    private function extractTypeExpression(string $body): ?string
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        $type   = '';
        $depth  = 0;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($depth === 0 && ($char === ' ' || $char === "\t" || $char === "\n")) {
                break;
            }

            if ($char === '<' || $char === '{' || $char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === '>' || $char === '}' || $char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            }

            $type .= $char;
        }

        $type = trim($type);

        return $type === '' ? null : $type;
    }

    /**
     * Extract the type expression from a PHPDoc type alias body after the alias name.
     *
     * @param string $body `phpstan-type`/`import-type` body whose alias name (and optional `=`) precedes the type.
     * @return string|null The aliased type expression, or null when no type follows the alias.
     */
    private function extractTypeAliasExpression(string $body): ?string
    {
        $body = trim($body);

        // Remove the alias name and optional equals sign before parsing the aliased type.
        if (preg_match('/^\S+\s+(?:=\s*)?(?<type>.+)$/s', $body, $matches) !== 1) {
            return null;
        }

        return $this->extractTypeExpression($matches['type']);
    }

    /**
     * Extract the parameter variable name from a @param body, or null when none is present.
     *
     * @param string $body `@param` tag body whose first `$name` token identifies the documented parameter.
     * @return string|null
     */
    private function extractParamName(string $body): ?string
    {
        // Capture the first PHPDoc parameter variable name in the tag body.
        if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)/', $body, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Detect whether the signature's typed declaration already says `mixed`, in which case the PHPDoc tag is not adding noise.
     *
     * @param Node        $node      Method, function, or property node whose native type hint is compared.
     * @param string      $tagKind   Lower-cased tag name driving which declaration (param / return / var) is checked.
     * @param string|null $paramName Variable name for a `@param`; null for return/var tags, which need no name match.
     * @return bool True when the docblock's standalone broad type mirrors the signature.
     */
    private function hasSignatureBroadTypeCoverage(Node $node, string $tagKind, ?string $paramName): bool
    {
        if (in_array($tagKind, self::PARAM_TAGS, true)) {
            if ($paramName === null) {
                return false;
            }

            if ($node instanceof ClassMethod || $node instanceof Function_) {
                foreach ($node->params as $param) {
                    if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                        continue;
                    }
                    if ($param->var->name === $paramName) {
                        return ModernisationNodeHelper::typeName($param->type) === 'mixed';
                    }
                }
            }

            return false;
        }

        if (in_array($tagKind, self::RETURN_TAGS, true)) {
            if ($node instanceof ClassMethod || $node instanceof Function_) {
                return ModernisationNodeHelper::typeName($node->returnType) === 'mixed';
            }

            return false;
        }

        if (in_array($tagKind, self::VAR_TAGS, true)) {
            if ($node instanceof Property) {
                return ModernisationNodeHelper::typeName($node->type) === 'mixed';
            }

            return false;
        }

        return false;
    }

    /**
     * Render a readable symbol for the finding: method/function name, property list, or constant list.
     *
     * @param Node $node Declaration node a finding points at; property/const lists render every declared name.
     * @return string The display symbol, or "unknown" when the node kind is unrecognised.
     */
    private function resolveSymbol(Node $node): string
    {
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($node);
        }

        if ($node instanceof Property) {
            $names = [];
            foreach ($node->props as $prop) {
                $names[] = '$' . $prop->name->toString();
            }

            return implode(', ', $names);
        }

        if ($node instanceof ClassConst) {
            $names = [];
            foreach ($node->consts as $const) {
                $names[] = $const->name->toString();
            }

            return implode(', ', $names);
        }

        return 'unknown';
    }

    /**
     * Drop a leading or trailing top-level `null` union member from a normalized type.
     *
     * A nullable bag such as `array<string, mixed>|null` is still an unstructured bag,
     * so its `null` union member is removed before the array-bag exemption check.
     *
     * @param string $type Whitespace-stripped, lowercased type expression.
     * @return string The type with a top-level `|null` / `null|` member removed, when present.
     */
    private function stripTopLevelNullUnion(string $type): string
    {
        if (str_ends_with($type, '|null')) {
            return substr($type, 0, -strlen('|null'));
        }

        if (str_starts_with($type, 'null|')) {
            return substr($type, strlen('null|'));
        }

        return $type;
    }
}
