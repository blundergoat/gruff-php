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
      * User flow: Decides whether this rule adds a finding to the user report.
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
     * Detect PHPDoc tags that use `mixed` where a narrower type would carry more meaning.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        // User view: choose the findings list branch for this case.
        if (!str_contains($analysisUnit->source, 'mixed')) {
            return [];
        }

        $definition = $this->definition();
        $findings   = [];

        $targets = NodeIndex::nodesOfAny(
            $analysisUnit,
            [ClassMethod::class, Function_::class, Property::class, ClassConst::class],
        );

        // User view: add each item that can appear in findings list.
        foreach ($targets as $node) {
            $doc = $node->getDocComment();
            // User view: choose the findings list branch for this case.
            if (!$doc instanceof Doc) {
                continue;
            }

            $symbol = $this->resolveSymbol($node);

            // User view: add each item that can appear in findings list.
            foreach ($this->extractTagBlocks($doc) as $block) {
                $finding = $this->findingForTagBlock(
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    node:         $node,
                    symbol:       $symbol,
                    block:        $block,
                );
                // User view: choose the findings list branch for this case.
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Build a mixed-overuse finding for one PHPDoc tag block, or null when an exemption applies.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        // User view: choose the findings list branch for this case.
        if (!$this->isScannedTag($tagKind)) {
            return null;
        }

        $analysis = $this->classifyMixedInBody($block['body'], in_array($tagKind, self::TYPE_ALIAS_TAGS, true));
        // User view: choose the findings list branch for this case.
        if (!$analysis['hasMixed'] || $this->isUnstructuredArrayBagType($block['body']) || $this->isPreciseArrayShape($block['body'])) {
            return null;
        }

        $paramName = in_array($tagKind, self::PARAM_TAGS, true)
            ? $this->extractParamName($block['body'])
            : null;

        // User view: choose the findings list branch for this case.
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
     * Detect whether the tag is one this rule examines (param / return / var / property / type-alias variants).
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
     * Extract PHPDoc tag bodies with their source line numbers.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: add each item that can appear in findings list.
        foreach ($lines as $offset => $rawLine) {
            $stripped = $this->stripDocPrefix($rawLine);

            // Split a PHPDoc line into the tag name and remaining tag body.
            // User view: choose the findings list branch for this case.
            if (preg_match('/^@([A-Za-z][A-Za-z0-9_-]*)\b\s*(.*)$/', $stripped, $matches) === 1) {
                // User view: choose the findings list branch for this case.
                // User view: missing data becomes the expected findings list state.
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

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($current !== null) {
                $current['body'] .= "\n" . $stripped;
            }
        }

        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Strip the leading `/**`, trailing `*​/`, and per-line `*` characters from a docblock line.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $line - One raw physical line of the docblock, still carrying its `*` framing.
     *
     * @return string - the line's textual content with opening, closing, and per-line `*` framing removed
     */
    private function stripDocPrefix(string $line): string
    {
        $trimmed = ltrim($line);
        // User view: missing data becomes a safe findings list default.
        $trimmed = preg_replace('/^\/\*+/', '', $trimmed) ?? $trimmed;
        // User view: missing data becomes a safe findings list default.
        $trimmed = preg_replace('/\*+\/$/', '', $trimmed) ?? $trimmed;
        $trimmed = ltrim($trimmed);
        // User view: missing data becomes a safe findings list default.
        $trimmed = preg_replace('/^\*+\s?/', '', $trimmed) ?? $trimmed;

        return $trimmed;
    }

    /**
     * Detect whether a PHPDoc tag contains a standalone mixed type.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($type === null || preg_match('/(?<![\\\\\w])mixed(?!\w)/i', $type) !== 1) {
            return ['hasMixed' => false, 'isStandalone' => false];
        }

        $standalone = strcasecmp($type, 'mixed') === 0;

        return ['hasMixed' => true, 'isStandalone' => $standalone];
    }

    /**
     * Detect unstructured decoded/config payload bags where mixed is the honest boundary type.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $body - Tag body whose leading type expression is tested for an array/list bag.
     *
     * @return bool - true for array/list bags whose leaves are unknown payload values, where mixed is the honest boundary type
     */
    private function isUnstructuredArrayBagType(string $body): bool
    {
        $type = $this->extractTypeExpression($body);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($type === null) {
            return false;
        }

        // User view: missing data becomes a safe findings list default.
        $type = $this->stripTopLevelNullUnion(strtolower(preg_replace('/\s+/', '', $type) ?? $type));

        return $this->isArrayBagType($type);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $type - Whitespace-stripped, lowercased type expression to test for an array/list generic.
     *
     * @return bool - true when the normalized type is a keyed-array or list generic bottoming out in mixed leaves; false for any other shape
     */
    private function isArrayBagType(string $type): bool
    {
        // Capture the value side of array-key/string/int keyed generic array types.
        // User view: choose the findings list branch for this case.
        if (preg_match('/^array<(?:array-key|string|int),(.+)>$/', $type, $matches) === 1) {
            return $this->isArrayBagValueType($matches[1]);
        }

        // Capture the element type from list generics.
        // User view: choose the findings list branch for this case.
        if (preg_match('/^list<(.+)>$/', $type, $matches) === 1) {
            return $this->isArrayBagValueType($matches[1]);
        }

        return false;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $type - Value side of an array/list generic; recursed when itself a nested bag.
     *
     * @return bool - true when the value type is `mixed` or recurses through nested array/list generics down to a mixed leaf
     */
    private function isArrayBagValueType(string $type): bool
    {
        // User view: choose the findings list branch for this case.
        if ($type === 'mixed') {
            return true;
        }

        return $this->isArrayBagType($type);
    }

    /**
     * Detect PHPStan/Psalm `array{...}` shapes that carry at least one field whose
     * type is not `mixed`. The nested mixed in that case describes a genuinely
     * heterogeneous leaf (e.g. JSON envelope payload) rather than type sloppiness.
     * An `array{value: mixed}` shape with no concrete sibling field is NOT
     * precise and still fires.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $body - Tag body whose leading type is inspected for an `array{...}` shape.
     *
     * @return bool - true when the type is an `array{...}` shape carrying at least one non-mixed sibling field, exempting it from the rule
     */
    private function isPreciseArrayShape(string $body): bool
    {
        $type = $this->extractTypeExpression($body);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($type === null) {
            return false;
        }

        // User view: missing data becomes a safe findings list default.
        $type = preg_replace('/\s+/', '', $type) ?? $type;
        // Match a whole PHPDoc array shape so only its top-level field list is analysed for `mixed` leaves.
        // User view: choose the findings list branch for this case.
        if (!preg_match('/^array\{(.*)\}$/i', $type, $matches)) {
            return false;
        }

        $inner = $matches[1];
        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($inner === '') {
            return false;
        }

        // User view: add each item that can appear in findings list.
        foreach ($this->splitTopLevelComma($inner) as $pair) {
            $colonIndex = $this->topLevelColonIndex($pair);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($colonIndex === null) {
                continue;
            }

            $fieldType = trim(substr($pair, $colonIndex + 1));
            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if ($fieldType !== '' && strcasecmp($fieldType, 'mixed') !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a string on top-level commas, ignoring commas nested inside `<>{}()[]`.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            // User view: choose the findings list branch for this case.
            if ($char === '<' || $char === '{' || $char === '(' || $char === '[') {
                $depth++;
            }
            // User view: choose the next findings list branch for this case.
            elseif ($char === '>' || $char === '}' || $char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            }

            // User view: choose the findings list branch for this case.
            if ($depth === 0 && $char === ',') {
                $segments[] = $segment;
                $segment    = '';
                continue;
            }

            $segment .= $char;
        }

        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($segment !== '') {
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * Return the index of the first top-level colon in a shape pair, or null when none exists.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $pair - A single `key: type` shape field, possibly nesting `<>{}()[]` in the type.
     *
     * @return int|null - zero-based colon offset for an associative shape field, or null for positional/malformed fields
     */
    private function topLevelColonIndex(string $pair): ?int
    {
        $depth  = 0;
        $length = strlen($pair);

        for ($i = 0; $i < $length; $i++) {
            $char = $pair[$i];

            // User view: choose the findings list branch for this case.
            if ($char === '<' || $char === '{' || $char === '(' || $char === '[') {
                $depth++;
            }
            // User view: choose the next findings list branch for this case.
            elseif ($char === '>' || $char === '}' || $char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            }
            // User view: choose the next findings list branch for this case.
            elseif ($char === ':' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Extract the leading type expression from a tag body, balancing generics / arrays / shapes.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $body - Tag body; the type is read up to the first top-level whitespace.
     *
     * @return string|null - the leading type token read up to the first top-level whitespace, or null when the body is empty
     */
    private function extractTypeExpression(string $body): ?string
    {
        $body = trim($body);
        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($body === '') {
            // An empty body has no type to extract.
            return null;
        }

        $type   = '';
        $depth  = 0;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            // User view: choose the findings list branch for this case.
            if ($depth === 0 && ($char === ' ' || $char === "\t" || $char === "\n")) {
                break;
            }

            // User view: choose the findings list branch for this case.
            if ($char === '<' || $char === '{' || $char === '(' || $char === '[') {
                $depth++;
            }
            // User view: choose the next findings list branch for this case.
            elseif ($char === '>' || $char === '}' || $char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            }

            $type .= $char;
        }

        $type = trim($type);

        // User view: an empty value becomes a clear findings list fallback.
        return $type === '' ? null : $type;
    }

    /**
     * Extract the type expression from a PHPDoc type alias body after the alias name.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $body - `phpstan-type`/`import-type` body whose alias name (and optional `=`) precedes the type.
     *
     * @return string|null - the type expression following the alias name, or null when only a bare alias name is present
     */
    private function extractTypeAliasExpression(string $body): ?string
    {
        $body = trim($body);

        // Remove the alias name and optional equals sign before parsing the aliased type.
        // User view: choose the findings list branch for this case.
        if (preg_match('/^\S+\s+(?:=\s*)?(?<type>.+)$/s', $body, $matches) !== 1) {
            return null;
        }

        return $this->extractTypeExpression($matches['type']);
    }

    /**
     * Extract the parameter variable name from a @param body, or null when none is present.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $body - `@param` tag body whose first parameter variable token identifies the documented parameter.
     *
     * @return string|null - the parameter name without its leading `$`, or null when the body carries no parameter variable token
     */
    private function extractParamName(string $body): ?string
    {
        // Capture the first PHPDoc parameter variable name in the tag body.
        // User view: choose the findings list branch for this case.
        if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)/', $body, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Detect whether the signature's typed declaration already says `mixed`, in which case the PHPDoc tag is not adding noise.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node        $node - Method, function, or property node whose native type hint is compared.
     * @param string      $tagKind - Lower-cased tag name driving which declaration (param / return / var) is checked.
     * @param string|null $paramName - Variable name for a `@param`; null for return/var tags, which need no name match.
     *
     * @return bool - true when the native param/return/var hint is itself `mixed`, so the PHPDoc tag mirrors it rather than adding noise
     */
    private function hasSignatureBroadTypeCoverage(Node $node, string $tagKind, ?string $paramName): bool
    {
        // User view: choose the findings list branch for this case.
        if (in_array($tagKind, self::PARAM_TAGS, true)) {
            return $this->hasParamSignatureMixedCoverage($node, $paramName);
        }

        // User view: choose the findings list branch for this case.
        if (in_array($tagKind, self::RETURN_TAGS, true)) {
            return $this->hasReturnSignatureMixedCoverage($node);
        }

        // User view: choose the findings list branch for this case.
        if (in_array($tagKind, self::VAR_TAGS, true)) {
            return $this->hasVarSignatureMixedCoverage($node);
        }

        return false;
    }

    /**
     * Check whether a PHPDoc param tag mirrors a native `mixed` parameter type.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node        $node - Method or function node to inspect.
     * @param string|null $paramName - Parameter name extracted from the PHPDoc tag body.
     *
     * @return bool - true when the named signature parameter exists and is natively typed `mixed`
     */
    private function hasParamSignatureMixedCoverage(Node $node, ?string $paramName): bool
    {
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($paramName === null || !($node instanceof ClassMethod || $node instanceof Function_)) {
            return false;
        }

        // User view: add each item that can appear in findings list.
        foreach ($node->params as $param) {
            // User view: choose the findings list branch for this case.
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($param->var->name === $paramName) {
                return ModernisationNodeHelper::typeName($param->type) === 'mixed';
            }
        }

        return false;
    }

    /**
     * Check whether a PHPDoc return tag mirrors a native `mixed` return type.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
     * Check whether a PHPDoc var tag mirrors a native `mixed` property type.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Property node to inspect.
     *
     * @return bool - true when the property is natively typed `mixed`
     */
    private function hasVarSignatureMixedCoverage(Node $node): bool
    {
        // User view: choose the findings list branch for this case.
        if (!$node instanceof Property) {
            return false;
        }

        return ModernisationNodeHelper::typeName($node->type) === 'mixed';
    }

    /**
     * Render a readable symbol for the finding: method/function name, property list, or constant list.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Declaration node a finding points at; property/const lists render every declared name.
     *
     * @return string - the display symbol (method/function name, or comma-joined property/const names), or "unknown" for an unrecognised node kind
     */
    private function resolveSymbol(Node $node): string
    {
        // User view: choose the findings list branch for this case.
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($node);
        }

        // User view: choose the findings list branch for this case.
        if ($node instanceof Property) {
            $names = [];
            // User view: add each item that can appear in findings list.
            foreach ($node->props as $prop) {
                $names[] = '$' . $prop->name->toString();
            }

            return implode(', ', $names);
        }

        // User view: choose the findings list branch for this case.
        if ($node instanceof ClassConst) {
            $names = [];
            // User view: add each item that can appear in findings list.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $type - Whitespace-stripped, lowercased type expression.
     *
     * @return string - the type with a top-level `|null` / `null|` member removed, or unchanged when it is not a top-level nullable union
     */
    private function stripTopLevelNullUnion(string $type): string
    {
        // User view: choose the findings list branch for this case.
        if (str_ends_with($type, '|null')) {
            return substr($type, 0, -strlen('|null'));
        }

        // User view: choose the findings list branch for this case.
        if (str_starts_with($type, 'null|')) {
            return substr($type, strlen('null|'));
        }

        return $type;
    }
}
