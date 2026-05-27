<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

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
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;

/**
 * Detects variable names that encode type suffixes.
 *
 * M51 uses the name-suffix-plus-narrowed-type contract rather than a
 * declared-type-only contract. A configured trailing type token is reported
 * when the declaration or local PHPDoc type matches the suffix, or when no
 * local type evidence contradicts it. `As<Type>` conversion idioms are exempt
 * so transient casts such as `$nameAsString` remain readable.
 *
 * Overlap deferral order is centralised in RuleRegistry:
 * class-file-mismatch > confusing-name > negative-boolean > boolean-prefix >
 * identifier-quality > hungarian-notation > suffix-hungarian > short-variable >
 * abbreviation-allowlist.
 */
final readonly class SuffixHungarianRule implements RuleInterface
{
    /** Stable identifier for the suffix-Hungarian rule. */
    public const ID = 'naming.suffix-hungarian';

    /** Type suffixes considered Hungarian notation at the end of identifiers. */
    private const TYPE_SUFFIXES = [
        'String',
        'Array',
        'List',
        'Map',
        'Set',
        'Hash',
        'Object',
        'Integer',
        'Float',
        'Boolean',
    ];

    /**
     * Describe the suffix-Hungarian rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Suffix Hungarian notation',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  ['typeSuffixes' => self::TYPE_SUFFIXES],
            description:     'Flags identifiers that duplicate type information with trailing suffixes such as String, Map, or Boolean.',
        );
    }

    /**
     * Find properties, parameters, and locals that encode type suffixes.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for configured suffixes.
     * @return list<Finding> Findings for suffix-Hungarian identifiers.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition          = $this->definition();
        $suffixes            = $this->normalisedSuffixes($ruleContext->settingsFor($definition)->stringListOption('typeSuffixes'));
        $identifierTokenizer = new IdentifierTokenizer();
        $findings            = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            foreach ($property->props as $prop) {
                $finding = $this->finding(
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    node:         $prop,
                    identifier:   ['kind' => 'property', 'name' => $prop->name->toString(), 'symbol' => '$' . $prop->name->toString()],
                    suffixes:     $suffixes,
                    tokenizer:    $identifierTokenizer,
                    type:         $property->type,
                );

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        foreach ((new FunctionLikeScopeWalker())->scopes($analysisUnit->statements) as $scope) {
            $symbol = $this->symbol($scope);

            foreach ($scope->node->params as $param) {
                if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                $finding = $this->finding(
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    node:         $param,
                    identifier:   ['kind' => $param->flags === 0 ? 'parameter' : 'property', 'name' => $param->var->name, 'symbol' => $symbol],
                    suffixes:     $suffixes,
                    tokenizer:    $identifierTokenizer,
                    type:         $param->type,
                );

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }

            foreach ($scope->localVariables as $name => $variable) {
                $suffixToken = $this->suffixToken($name, $suffixes, $identifierTokenizer);
                if ($suffixToken === null || !$this->allowsLocalTypeSuffix($variable, $suffixes[$suffixToken])) {
                    continue;
                }

                $finding = $this->finding(
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    node:         $variable,
                    identifier:   ['kind' => 'variable', 'name' => $name, 'symbol' => $symbol],
                    suffixes:     $suffixes,
                    tokenizer:    $identifierTokenizer,
                    type:         null,
                );

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array{kind: string, name: string, symbol: string|null} $identifier
     * @param array<string, string>                                  $suffixes   Map of lower-case suffix token to configured display suffix.
     * @return Finding|null Finding for an identifier with a type suffix.
     */
    private function finding(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        Node $node,
        array $identifier,
        array $suffixes,
        IdentifierTokenizer $tokenizer,
        ?Node $type,
    ): ?Finding {
        $kind        = $identifier['kind'];
        $name        = $identifier['name'];
        $symbol      = $identifier['symbol'];
        $suffixToken = $this->suffixToken($name, $suffixes, $tokenizer);
        if ($suffixToken === null) {
            return null;
        }

        $suffix = $suffixes[$suffixToken];
        if ($this->doesTypeContradictSuffix($type, $suffixToken)) {
            return null;
        }

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s $%s encodes the type suffix "%s".', ucfirst($kind), $name, $suffix),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: sprintf('Rename $%s to describe its role without repeating the type. If the suffix is a domain convention rather than type-restating, remove it from `rules.naming.suffix-hungarian.options.typeSuffixes` in `.gruff-php.yaml`.', $name),
            metadata:    ['identifierKind' => $kind, 'identifierName' => $name, 'suffix' => $suffix],
        );
    }

    /**
     * Check local PHPDoc `@var` evidence when it exists.
     *
     * @return bool True when no local doc type contradicts the suffix.
     */
    private function allowsLocalTypeSuffix(Variable $variable, string $suffix): bool
    {
        $docType = $this->localVarDocType($variable);

        return $docType === null || $this->matchesDocTypeSuffix($docType, strtolower($suffix));
    }

    /**
     * @param array<string, string> $suffixes
     * @return string|null Lower-case suffix token matched at the end of the name.
     */
    private function suffixToken(string $name, array $suffixes, IdentifierTokenizer $tokenizer): ?string
    {
        $tokens = $tokenizer->tokenize($name);
        if (count($tokens) < 2 || $this->isConversionIdiom($tokens)) {
            return null;
        }

        $suffixToken = $tokens[array_key_last($tokens)];

        return isset($suffixes[$suffixToken]) ? $suffixToken : null;
    }

    /**
     * Read the nearest local `@var` type attached to a variable assignment.
     *
     * @return string|null PHPDoc type text when present.
     */
    private function localVarDocType(Variable $variable): ?string
    {
        $parent = $variable->getAttribute('parent');

        while ($parent instanceof Node) {
            $docComment = $parent->getDocComment();
            // Read an adjacent @var type assertion before using it to infer suffix intent.
            if ($docComment !== null && preg_match('/@var\s+([^\s]+)/', $docComment->getText(), $matches) === 1) {
                return $matches[1];
            }

            if ($parent instanceof Expression || $parent instanceof ClassMethod || $parent instanceof Function_) {
                return null;
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * @return bool True when the declared type exists and does not support the suffix.
     */
    private function doesTypeContradictSuffix(?Node $type, string $suffix): bool
    {
        if ($type === null) {
            return false;
        }

        $typeName = $this->singleTypeName($type);

        return $typeName !== null && !$this->matchesTypeNameSuffix($typeName, $suffix);
    }

    /**
     * Check whether a single-arm PHPDoc type supports the configured suffix.
     *
     * @return bool True when the PHPDoc type matches the suffix.
     */
    private function matchesDocTypeSuffix(string $type, string $suffix): bool
    {
        $normalised = trim($type, " \t\n\r\0\x0B?");
        $arms       = array_values(array_filter(
            preg_split('/\|/', $normalised) ?: [],
            static fn (string $arm): bool => strtolower(trim($arm)) !== 'null',
        ));

        if (count($arms) !== 1) {
            return false;
        }

        return $this->matchesTypeNameSuffix($arms[0], $suffix);
    }

    /**
     * Check whether a native or short type name supports the configured suffix.
     *
     * @return bool True when the type name matches the suffix.
     */
    private function matchesTypeNameSuffix(string $typeName, string $suffix): bool
    {
        $normalised = strtolower(ltrim($typeName, '\\'));
        $normalised = preg_replace('/(?:<.*>|\\{.*|\\[\\])$/', '', $normalised) ?? $normalised;
        $parts      = explode('\\', $normalised);
        $shortName  = $parts[array_key_last($parts)] ?? $normalised;

        return match ($suffix) {
            'string' => $shortName === 'string',
            'array', 'list', 'map', 'set', 'hash' => in_array($shortName, ['array', 'iterable', 'list', 'non-empty-list'], true),
            'integer' => in_array($shortName, ['int', 'integer'], true),
            'float' => $shortName === 'float',
            'boolean' => in_array($shortName, ['bool', 'boolean'], true),
            'object' => $shortName === 'object',
            default => false,
        };
    }

    /**
     * Resolve nullable or simple single-arm types to one type name.
     *
     * @return string|null Type name when the declaration has exactly one non-null arm.
     */
    private function singleTypeName(Node $type): ?string
    {
        if ($type instanceof NullableType) {
            return $this->singleTypeName($type->type);
        }

        if ($type instanceof Identifier || $type instanceof Name) {
            return $type->toString();
        }

        if ($type instanceof UnionType) {
            $nonNull = array_values(array_filter(
                $type->types,
                static fn (Node $node): bool => !($node instanceof Identifier && $node->toLowerString() === 'null'),
            ));

            return count($nonNull) === 1 ? $this->singleTypeName($nonNull[0]) : null;
        }

        return null;
    }

    /**
     * Normalize configured suffixes to case-insensitive lookup keys.
     *
     * @param list<string> $suffixes
     * @return array<string, string>
     */
    private function normalisedSuffixes(array $suffixes): array
    {
        $normalised = [];

        foreach ($suffixes as $suffix) {
            $token = strtolower($suffix);
            if ($token !== '') {
                $normalised[$token] = $suffix;
            }
        }

        return $normalised;
    }

    /**
     * @param list<string> $tokens
     * @return bool True when the suffix is part of an explicit conversion idiom.
     */
    private function isConversionIdiom(array $tokens): bool
    {
        if (count($tokens) < 3) {
            return false;
        }

        $previousToken = $tokens[array_key_last($tokens) - 1];

        return in_array($previousToken, ['as', 'to'], true);
    }

    /**
     * Resolve the human-readable symbol for a function-like scope.
     *
     * @return string Named callable symbol or synthetic closure/arrow label.
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }
}
