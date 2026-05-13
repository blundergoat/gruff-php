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
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

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
        return new RuleDefinition(
            id:              self::ID,
            name:            'PHPDoc mixed overuse',
            pillar:          Pillar::Modernisation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Detect PHPDoc tags that use `mixed` where a narrower type would carry more meaning.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding>
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder     = new NodeFinder();
        $findings   = [];

        $targets = $finder->find(
            $unit->statements,
            static fn (Node $node): bool => $node instanceof ClassMethod
                || $node instanceof Function_
                || $node instanceof Property
                || $node instanceof ClassConst,
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

                $analysis = $this->classifyMixedInBody($block['body']);
                if (!$analysis['hasMixed']) {
                    continue;
                }

                if ($this->isUnstructuredArrayBagType($block['body'])) {
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
                    filePath:    $unit->file->displayPath,
                    line:        $block['line'],
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $symbol,
                    remediation: 'Prefer a narrower PHPDoc type than mixed (named class, value object, union, or bounded generic). gruff-php reports only.',
                    metadata:    [
                        'tagKind' => $tagKind,
                        'paramName' => $paramName,
                        'snippet' => trim($block['body']),
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * Detect whether the tag is one this rule examines (param / return / var / property / type-alias variants).
     *
     * @return bool
     */
    private function isScannedTag(string $tag): bool
    {
        return in_array($tag, self::PARAM_TAGS, true)
            || in_array($tag, self::RETURN_TAGS, true)
            || in_array($tag, self::VAR_TAGS, true)
            || in_array($tag, self::PROPERTY_TAGS, true)
            || in_array($tag, self::TYPE_ALIAS_TAGS, true);
    }

    /**
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

        return $blocks;
    }

    /**
     * Strip the leading `/**`, trailing `*​/`, and per-line `*` characters from a docblock line.
     *
     * @return string The line's textual content without the docblock framing.
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
     * @return array{hasMixed: bool, isStandalone: bool}
     */
    private function classifyMixedInBody(string $body): array
    {
        if (preg_match('/(?<![\\\\\w])mixed(?!\w)/i', $body) !== 1) {
            return ['hasMixed' => false, 'isStandalone' => false];
        }

        $type       = $this->extractTypeExpression($body);
        $standalone = $type !== null && strcasecmp($type, 'mixed') === 0;

        return ['hasMixed' => true, 'isStandalone' => $standalone];
    }

    /**
     * Detect unstructured decoded/config payload bags where mixed is the honest boundary type.
     *
     * @return bool True for array/list bags whose leaves are unknown payload values.
     */
    private function isUnstructuredArrayBagType(string $body): bool
    {
        $type = $this->extractTypeExpression($body);
        if ($type === null) {
            return false;
        }

        $type = strtolower(preg_replace('/\s+/', '', $type) ?? $type);

        return $this->isArrayBagType($type);
    }

    /**
     * @return bool True when the normalized type is an array/list bag with mixed leaves.
     */
    private function isArrayBagType(string $type): bool
    {
        if (preg_match('/^array<(?:array-key|string|int),(.+)>$/', $type, $matches) === 1) {
            return $this->isArrayBagValueType($matches[1]);
        }

        if (preg_match('/^list<(.+)>$/', $type, $matches) === 1) {
            return $this->isArrayBagValueType($matches[1]);
        }

        return false;
    }

    /**
     * @return bool True when an array/list bag value type ends in mixed payload leaves.
     */
    private function isArrayBagValueType(string $type): bool
    {
        if ($type === 'mixed') {
            return true;
        }

        return $this->isArrayBagType($type);
    }

    /**
     * Extract the leading type expression from a tag body, balancing generics / arrays / shapes.
     *
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
     * Extract the parameter variable name from a @param body, or null when none is present.
     *
     * @return string|null
     */
    private function extractParamName(string $body): ?string
    {
        if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)/', $body, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Detect whether the signature's typed declaration already says `mixed`, in which case the PHPDoc tag is not adding noise.
     *
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
}
