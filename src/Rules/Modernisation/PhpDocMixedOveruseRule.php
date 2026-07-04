<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Modernisation;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;

/**
 * Flags a PHPDoc param, return, var, or property tag that types a value as `mixed` where a narrower type
 * would tell the reader (and static analysis) more, so the user can tighten the documentation.
 *
 * Runs per file, bailing fast when the source has no `mixed` at all. For each documented declaration it
 * parses the tag blocks and reports a `mixed` tag - unless the value is an honest unstructured payload bag
 * (`array<string, mixed>`), a precise `array{...}` envelope with a concrete sibling field, or simply
 * mirrors a native `mixed` hint already on the signature. Advisory only.
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
     * Describes the PHPDoc mixed-overuse rule for the registry and reports.
     *
     * @return RuleDefinition - the rule's fixed identity, pillar, default severity, and false-positive shapes for the registry
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                  self::ID,
            name:                'PHPDoc mixed overuse',
            pillar:              Pillar::Modernisation,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Advisory,
            confidence:          Confidence::Medium,
            description:         'Flags PHPDoc @param/@return/@var/@property tags using mixed where a narrower type would carry more meaning. Unstructured array bags and precise array{...} envelope shapes are exempt.',
            falsePositiveShapes: [
                                     [
                                         'shape'      => 'JSON-boundary helpers consuming json_decode output where every top-level shape is possible.',
                                         'mitigation' => 'Narrow @param/@return to `array|bool|float|int|string|null` - the supertype of any top-level decoded value.',
                                     ],
                                     [
                                         'shape'      => 'Envelope shapes with mixed leaves (FHIR resources, LLM tool-call args).',
                                         'mitigation' => 'Use `array{key: type, ...}` syntax with at least one concrete sibling field; precise envelopes are exempted.',
                                     ],
                                 ],
        );
    }

    /**
     * Reports each PHPDoc tag that types a value as `mixed` where a narrower type would carry more meaning.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per mixed-overuse tag, in source order; empty when no tag warrants narrowing
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // Fast bail: nothing to find when no `mixed` literal appears in the
        // file's source text.
        if (!str_contains($analysisUnit->source, 'mixed')) {
            return [];
        }

        $definition = $this->definition();
        $findings   = [];

        $targets = NodeIndex::nodesOfAny(
            $analysisUnit,
            [ClassMethod::class, Function_::class, Property::class, ClassConst::class],
        );

        // Inspect each documented method, function, property, and constant.
        foreach ($targets as $node) {
            $doc = $node->getDocComment();
            // Skip a declaration that carries no docblock to scan.
            if (!$doc instanceof Doc) {
                continue;
            }

            $symbol = $this->resolveSymbol($node);

            // Weigh each tag in the docblock.
            foreach ($this->extractTagBlocks($doc) as $block) {
                $finding = $this->findingForTagBlock(
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    node:         $node,
                    symbol:       $symbol,
                    block:        $block,
                );
                // Collect a finding when this tag warrants narrowing.
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Builds a mixed-overuse finding for one PHPDoc tag block, or null when an exemption applies.
     *
     * @param RuleDefinition                          $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit                            $analysisUnit - Parsed unit that owns the documented node.
     * @param Node                                    $node - Documented declaration whose signature may mirror `mixed`.
     * @param string                                  $symbol - Rendered symbol used in the finding message.
     * @param array{tag: string, body: string, line: int} $block - Parsed PHPDoc tag block with source line.
     *
     * @return Finding|null - finding for a reportable `mixed` tag, or null when the tag is unscanned or exempt
     */
    private function findingForTagBlock(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        Node $node,
        string $symbol,
        array $block,
    ): ?Finding {
        $tagKind = $block['tag'];
        // Only param/return/var/property/type-alias tags are in scope for this rule.
        if (!$this->isScannedTag($tagKind)) {
            return null;
        }

        $analysis = $this->classifyMixedInBody($block['body'], in_array($tagKind, self::TYPE_ALIAS_TAGS, true));
        // A tag with no mixed, an honest payload bag, or a precise shape is fine as it stands.
        if (!$analysis['hasMixed'] || $this->isUnstructuredArrayBagType($block['body']) || $this->isPreciseArrayShape($block['body'])) {
            return null;
        }

        $paramName = in_array($tagKind, self::PARAM_TAGS, true)
            ? $this->extractParamName($block['body'])
            : null;

        // A standalone mixed that only mirrors a native `mixed` hint adds no noise, so leave it.
        if ($analysis['isStandalone'] && $this->hasSignatureBroadTypeCoverage($node, $tagKind, $paramName)) {
            return null;
        }

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s has @%s using mixed; prefer a narrower PHPDoc type.', $symbol, $tagKind),
            filePath:    $analysisUnit->file->displayPath,
            line:        $block['line'],
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Narrow the PHPDoc type to match what the function actually accepts. For JSON-boundary helpers use `array|bool|float|int|string|null`. For envelope shapes where the leaf is genuinely heterogeneous (e.g. FHIR resources, LLM tool-call args), name the envelope precisely with `array{...}` syntax — precise shapes are exempted by the rule. When only one input shape is meaningful, narrow to that concrete type (`?string`, `int|float|null`, a named class).',
            metadata:    [
                             'tagKind'   => $tagKind,
                             'paramName' => $paramName,
                             'snippet'   => trim($block['body']),
                         ],
        );
    }

    /**
     * Reports whether the tag is one this rule examines (param / return / var / property / type-alias variants).
     *
     * @param string $tagName - Lower-cased PHPDoc tag name (without the leading `@`) to classify.
     *
     * @return bool - true when the tag belongs to a scanned family (param/return/var/property/type-alias) worth narrowing
     */
    private function isScannedTag(string $tagName): bool
    {
        return in_array($tagName, self::PARAM_TAGS, true)
               || in_array($tagName, self::RETURN_TAGS, true)
               || in_array($tagName, self::VAR_TAGS, true)
               || in_array($tagName, self::PROPERTY_TAGS, true)
               || in_array($tagName, self::TYPE_ALIAS_TAGS, true);
    }

    /**
     * Extracts each PHPDoc tag body with its source line number, joining multi-line bodies.
     *
     * @param Doc $doc - Docblock attached to the node under inspection; multi-line tag bodies are joined.
     *
     * @return list<array{tag: string, body: string, line: int}> - one entry per docblock tag in source order, each with its lower-cased tag name,
     *                         joined multi-line body, and absolute source line
     */
    private function extractTagBlocks(Doc $doc): array
    {
        $startLine = $doc->getStartLine();
        $lines     = preg_split('/\R/', $doc->getText()) ?: [];

        $blocks  = [];
        $current = null;

        // Walk the docblock line by line.
        foreach ($lines as $offset => $rawLine) {
            $stripped = $this->stripDocPrefix($rawLine);

            // Split a PHPDoc line into the tag name and remaining tag body.
            if (preg_match('/^@([A-Za-z][A-Za-z0-9_-]*)\b\s*(.*)$/', $stripped, $matches) === 1) {
                // Flush the tag we were building before starting the new one.
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = [
                    'tag'  => strtolower($matches[1]),
                    'body' => $matches[2],
                    'line' => $startLine + $offset,
                ];
                continue;
            }

            // A non-tag line continues the current tag's body.
            if ($current !== null) {
                $current['body'] .= "\n" . $stripped;
            }
        }

        // Flush the final tag once the docblock ends.
        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Strips the leading `/**`, trailing `*​/`, and per-line `*` framing from a docblock line.
     *
     * @param string $line - One raw physical line of the docblock, still carrying its `*` framing.
     *
     * @return string - the line's textual content with opening, closing, and per-line `*` framing removed
     */
    private function stripDocPrefix(string $line): string
    {
        $trimmed = ltrim($line);
        $trimmed = preg_replace('/^\/\*+/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\*+\/$/', '', $trimmed) ?? $trimmed;
        $trimmed = ltrim($trimmed);
        $trimmed = preg_replace('/^\*+\s?/', '', $trimmed) ?? $trimmed;

        return $trimmed;
    }

    /**
     * Reports whether a PHPDoc tag body contains a standalone `mixed` type, and whether it is exactly `mixed`.
     *
     * @param string $body - Tag body after the tag name, e.g. the text following `@param`.
     * @param bool   $isTypeAlias - True when the body is a `phpstan-type`/`import-type` alias, so the alias
     *                            name is skipped before the aliased type is parsed.
     *
     * @return array{hasMixed: bool, isStandalone: bool} - hasMixed flags any `mixed` token in the type; isStandalone is true only when the whole
     *                         type is exactly `mixed`
     */
    private function classifyMixedInBody(string $body, bool $isTypeAlias): array
    {
        $type = $isTypeAlias
            ? $this->extractTypeAliasExpression($body)
            : $this->extractTypeExpression($body);

        // Find standalone `mixed` tokens without matching substrings inside class names.
        if ($type === null || preg_match('/(?<![\\\\\w])mixed(?!\w)/i', $type) !== 1) {
            return ['hasMixed' => false, 'isStandalone' => false];
        }

        $standalone = strcasecmp($type, 'mixed') === 0;

        return ['hasMixed' => true, 'isStandalone' => $standalone];
    }

    /**
     * Reports whether the tag types an unstructured payload bag, where `mixed` is the honest boundary type.
     *
     * @param string $body - Tag body whose leading type expression is tested for an array/list bag.
     *
     * @return bool - true for array/list bags whose leaves are unknown payload values, where mixed is the honest boundary type
     */
    private function isUnstructuredArrayBagType(string $body): bool
    {
        $type = $this->extractTypeExpression($body);
        // No leading type means nothing to classify as a bag.
        if ($type === null) {
            return false;
        }

        $type = $this->stripTopLevelNullUnion(strtolower(preg_replace('/\s+/', '', $type) ?? $type));

        return $this->isArrayBagType($type);
    }

    /**
     * @param string $type - Whitespace-stripped, lowercased type expression to test for an array/list generic.
     *
     * @return bool - true when the normalized type is a keyed-array or list generic bottoming out in mixed leaves; false for any other shape
     */
    private function isArrayBagType(string $type): bool
    {
        // Capture the value side of array-key/string/int keyed generic array types.
        if (preg_match('/^array<(?:array-key|string|int),(.+)>$/', $type, $matches) === 1) {
            return $this->isArrayBagValueType($matches[1]);
        }

        // Capture the element type from list generics.
        if (preg_match('/^list<(.+)>$/', $type, $matches) === 1) {
            return $this->isArrayBagValueType($matches[1]);
        }

        return false;
    }

    /**
     * @param string $type - Value side of an array/list generic; recursed when itself a nested bag.
     *
     * @return bool - true when the value type is `mixed` or recurses through nested array/list generics down to a mixed leaf
     */
    private function isArrayBagValueType(string $type): bool
    {
        // A mixed leaf is the bag signature we accept as honest.
        if ($type === 'mixed') {
            return true;
        }

        return $this->isArrayBagType($type);
    }

    /**
     * Reports whether the type is a precise `array{...}` shape carrying at least one non-mixed field.
     *
     * The nested mixed in that case describes a genuinely heterogeneous leaf (e.g. JSON envelope payload)
     * rather than type sloppiness. An `array{value: mixed}` shape with no concrete sibling field is NOT
     * precise and still fires.
     *
     * @param string $body - Tag body whose leading type is inspected for an `array{...}` shape.
     *
     * @return bool - true when the type is an `array{...}` shape carrying at least one non-mixed sibling field, exempting it from the rule
     */
    private function isPreciseArrayShape(string $body): bool
    {
        $type = $this->extractTypeExpression($body);
        // No leading type means there is no shape to inspect.
        if ($type === null) {
            return false;
        }

        $type = preg_replace('/\s+/', '', $type) ?? $type;
        // Match a whole PHPDoc array shape so only its top-level field list is analysed for `mixed` leaves.
        if (!preg_match('/^array\{(.*)\}$/i', $type, $matches)) {
            return false;
        }

        $inner = $matches[1];
        // An empty `array{}` has no fields to weigh.
        if ($inner === '') {
            return false;
        }

        // Inspect each field of the shape.
        foreach ($this->splitTopLevelComma($inner) as $pair) {
            $colonIndex = $this->topLevelColonIndex($pair);
            // A positional field has no `key: type` colon to read.
            if ($colonIndex === null) {
                continue;
            }

            $fieldType = trim(substr($pair, $colonIndex + 1));
            // One concrete sibling field makes the whole shape precise, and thus exempt.
            if ($fieldType !== '' && strcasecmp($fieldType, 'mixed') !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Splits a string on top-level commas, keeping commas nested inside `<>{}()[]` intact.
     *
     * @param string $body - Inner shape text (between `array{` and `}`) to split into field pairs.
     *
     * @return list<string> - one segment per top-level field with bracket-nested commas kept intact; empty when the input is empty
     */
    private function splitTopLevelComma(string $body): array
    {
        $segments = [];
        $segment  = '';
        $depth    = 0;
        $length   = strlen($body);

        // Scan the text character by character, tracking bracket depth.
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            // Opening brackets deepen the nesting, closing brackets lift it.
            if ($char === '<' || $char === '{' || $char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === '>' || $char === '}' || $char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            }

            // A comma at the top level ends one field.
            if ($depth === 0 && $char === ',') {
                $segments[] = $segment;
                $segment    = '';
                continue;
            }

            $segment .= $char;
        }

        // Keep the trailing field after the last comma.
        if ($segment !== '') {
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * Returns the index of the first top-level colon in a shape pair, or null when none exists.
     *
     * @param string $pair - A single `key: type` shape field, possibly nesting `<>{}()[]` in the type.
     *
     * @return int|null - zero-based colon offset for an associative shape field, or null for positional/malformed fields
     */
    private function topLevelColonIndex(string $pair): ?int
    {
        $depth  = 0;
        $length = strlen($pair);

        // Scan for the first colon that sits outside any bracket.
        for ($i = 0; $i < $length; $i++) {
            $char = $pair[$i];

            // Track bracket depth so a colon nested inside a generic is ignored.
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
     * Extracts the leading type expression from a tag body, balancing generics, arrays, and shapes.
     *
     * @param string $body - Tag body; the type is read up to the first top-level whitespace.
     *
     * @return string|null - the leading type token read up to the first top-level whitespace, or null when the body is empty
     */
    private function extractTypeExpression(string $body): ?string
    {
        $body = trim($body);
        if ($body === '') {
            // An empty body has no type to extract.
            return null;
        }

        $type   = '';
        $depth  = 0;
        $length = strlen($body);

        // Read characters until the first top-level whitespace ends the type token.
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            // Unnested whitespace marks the end of the type token.
            if ($depth === 0 && ($char === ' ' || $char === "\t" || $char === "\n")) {
                break;
            }

            // Track bracket depth so whitespace inside a generic does not end the token early.
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
     * Extracts the type expression from a PHPDoc type-alias body, after the alias name.
     *
     * @param string $body - `phpstan-type`/`import-type` body whose alias name (and optional `=`) precedes the type.
     *
     * @return string|null - the type expression following the alias name, or null when only a bare alias name is present
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
     * Extracts the parameter variable name from a `@param` body, or null when none is present.
     *
     * @param string $body - `@param` tag body whose first parameter variable token identifies the documented parameter.
     *
     * @return string|null - the parameter name without its leading `$`, or null when the body carries no parameter variable token
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
     * Reports whether the signature's native type already says `mixed`, so the PHPDoc tag only mirrors it.
     *
     * @param Node        $node - Method, function, or property node whose native type hint is compared.
     * @param string      $tagKind - Lower-cased tag name driving which declaration (param / return / var) is checked.
     * @param string|null $paramName - Variable name for a `@param`; null for return/var tags, which need no name match.
     *
     * @return bool - true when the native param/return/var hint is itself `mixed`, so the PHPDoc tag mirrors it rather than adding noise
     */
    private function hasSignatureBroadTypeCoverage(Node $node, string $tagKind, ?string $paramName): bool
    {
        // A param tag is checked against the matching parameter's native type.
        if (in_array($tagKind, self::PARAM_TAGS, true)) {
            return $this->hasParamSignatureMixedCoverage($node, $paramName);
        }

        // A return tag is checked against the native return type.
        if (in_array($tagKind, self::RETURN_TAGS, true)) {
            return $this->hasReturnSignatureMixedCoverage($node);
        }

        // A var tag is checked against the property's native type.
        if (in_array($tagKind, self::VAR_TAGS, true)) {
            return $this->hasVarSignatureMixedCoverage($node);
        }

        return false;
    }

    /**
     * Reports whether a PHPDoc param tag merely mirrors a native `mixed` parameter type.
     *
     * @param Node        $node - Method or function node to inspect.
     * @param string|null $paramName - Parameter name extracted from the PHPDoc tag body.
     *
     * @return bool - true when the named signature parameter exists and is natively typed `mixed`
     */
    private function hasParamSignatureMixedCoverage(Node $node, ?string $paramName): bool
    {
        // Without a parameter name and a callable node there is nothing to match against.
        if ($paramName === null || !($node instanceof ClassMethod || $node instanceof Function_)) {
            return false;
        }

        // Find the signature parameter that matches the documented name.
        foreach ($node->params as $param) {
            // Skip a parameter with no plain, static name.
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                continue;
            }

            // A native `mixed` on the matching parameter means the tag only mirrors it.
            if ($param->var->name === $paramName) {
                return ModernisationNodeHelper::typeName($param->type) === 'mixed';
            }
        }

        return false;
    }

    /**
     * Reports whether a PHPDoc return tag merely mirrors a native `mixed` return type.
     *
     * @param Node $node - Method or function node to inspect.
     *
     * @return bool - true when the callable natively returns `mixed`
     */
    private function hasReturnSignatureMixedCoverage(Node $node): bool
    {
        return ($node instanceof ClassMethod || $node instanceof Function_)
            && ModernisationNodeHelper::typeName($node->returnType) === 'mixed';
    }

    /**
     * Reports whether a PHPDoc var tag merely mirrors a native `mixed` property type.
     *
     * @param Node $node - Property node to inspect.
     *
     * @return bool - true when the property is natively typed `mixed`
     */
    private function hasVarSignatureMixedCoverage(Node $node): bool
    {
        // Only a property declaration carries a var type to compare against.
        if (!$node instanceof Property) {
            return false;
        }

        return ModernisationNodeHelper::typeName($node->type) === 'mixed';
    }

    /**
     * Renders a readable symbol for the finding: method/function name, property list, or constant list.
     *
     * @param Node $node - Declaration node a finding points at; property/const lists render every declared name.
     *
     * @return string - the display symbol (method/function name, or comma-joined property/const names), or "unknown" for an unrecognised node kind
     */
    private function resolveSymbol(Node $node): string
    {
        // A method or function renders as its qualified name.
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($node);
        }

        // A property declaration renders every declared name.
        if ($node instanceof Property) {
            $names = [];
            foreach ($node->props as $prop) {
                $names[] = '$' . $prop->name->toString();
            }

            return implode(', ', $names);
        }

        // A constant declaration renders every declared name.
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
     * Drops a leading or trailing top-level `null` union member from a normalized type.
     *
     * A nullable bag such as `array<string, mixed>|null` is still an unstructured bag,
     * so its `null` union member is removed before the array-bag exemption check.
     *
     * @param string $type - Whitespace-stripped, lowercased type expression.
     *
     * @return string - the type with a top-level `|null` / `null|` member removed, or unchanged when it is not a top-level nullable union
     */
    private function stripTopLevelNullUnion(string $type): string
    {
        // Drop a trailing `|null` union member.
        if (str_ends_with($type, '|null')) {
            return substr($type, 0, -strlen('|null'));
        }

        // Drop a leading `null|` union member.
        if (str_starts_with($type, 'null|')) {
            return substr($type, strlen('null|'));
        }

        return $type;
    }
}
