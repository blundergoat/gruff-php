<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

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
 * Flags a variable, property, or parameter whose name ends in a type suffix - `$nameString`, `$itemsArray`,
 * `$flagBoolean` - so the user drops the redundant tag when the declared or documented type already says it.
 *
 * A configured trailing token is reported when the declaration or local PHPDoc type matches the suffix, or
 * when no local type evidence contradicts it. `As<Type>` / `To<Type>` conversion idioms are exempt so a
 * transient cast such as `$nameAsString` stays readable. Advisory, medium confidence.
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
     * Describes the suffix-Hungarian rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
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
     * Reports properties, parameters, and locals whose names end in a redundant type suffix.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for configured suffixes.
     *
     * @return list<Finding> - Findings for suffix-Hungarian identifiers.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition          = $this->definition();
        $suffixes            = $this->normalisedSuffixes($ruleContext->settingsFor($definition)->stringListOption('typeSuffixes'));
        $identifierTokenizer = new IdentifierTokenizer();
        $findings            = [];

        // Check every declared property in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            array_push(
                $findings,
                ...$this->propertyFindings(
                    definition:        $definition,
                    analysisUnit:      $analysisUnit,
                    property:          $property,
                    suffixes:          $suffixes,
                    tokenizer:         $identifierTokenizer,
                ),
            );
        }

        // Check parameters and locals across every function-like scope.
        foreach ((new FunctionLikeScopeWalker())->scopes($analysisUnit->statements) as $scope) {
            array_push(
                $findings,
                ...$this->scopeFindings(
                    definition:        $definition,
                    analysisUnit:      $analysisUnit,
                    scope:             $scope,
                    suffixes:          $suffixes,
                    tokenizer:         $identifierTokenizer,
                ),
            );
        }

        return $findings;
    }

    /**
     * Builds suffix-Hungarian findings for the properties in one property statement.
     *
     * @param RuleDefinition        $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit          $analysisUnit - Parsed unit that owns the property declaration.
     * @param Property              $property - Property statement whose individual props are inspected.
     * @param array<string, string> $suffixes - Map of lower-case suffix token to configured display suffix.
     * @param IdentifierTokenizer   $tokenizer - Splits names into tokens so trailing type suffixes can be isolated.
     *
     * @return list<Finding> - property suffix findings in declaration order
     */
    private function propertyFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        Property $property,
        array $suffixes,
        IdentifierTokenizer $tokenizer,
    ): array {
        $findings = [];

        // One declaration can name several properties, so check each in turn.
        foreach ($property->props as $prop) {
            $finding = $this->finding(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                node:         $prop,
                identifier:   ['kind' => 'property', 'name' => $prop->name->toString(), 'symbol' => '$' . $prop->name->toString()],
                suffixes:     $suffixes,
                tokenizer:    $tokenizer,
                type:         $property->type,
            );

            // Keep the property only when its name carries a type suffix.
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Builds suffix-Hungarian findings for the parameters and locals in one callable scope.
     *
     * @param RuleDefinition        $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit          $analysisUnit - Parsed unit that owns the callable scope.
     * @param FunctionLikeScope     $scope - Callable scope whose identifiers are inspected.
     * @param array<string, string> $suffixes - Map of lower-case suffix token to configured display suffix.
     * @param IdentifierTokenizer   $tokenizer - Splits names into tokens so trailing type suffixes can be isolated.
     *
     * @return list<Finding> - parameter and local suffix findings in source order
     */
    private function scopeFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        array $suffixes,
        IdentifierTokenizer $tokenizer,
    ): array {
        return [
            ...$this->parameterFindings(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                scope:        $scope,
                suffixes:     $suffixes,
                tokenizer:    $tokenizer,
            ),
            ...$this->localVariableFindings(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                scope:        $scope,
                suffixes:     $suffixes,
                tokenizer:    $tokenizer,
            ),
        ];
    }

    /**
     * Builds suffix-Hungarian findings for the parameters in one callable scope.
     *
     * @param RuleDefinition        $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit          $analysisUnit - Parsed unit that owns the callable scope.
     * @param FunctionLikeScope     $scope - Callable scope whose parameters are inspected.
     * @param array<string, string> $suffixes - Map of lower-case suffix token to configured display suffix.
     * @param IdentifierTokenizer   $tokenizer - Splits names into tokens so trailing type suffixes can be isolated.
     *
     * @return list<Finding> - parameter suffix findings in declaration order
     */
    private function parameterFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        array $suffixes,
        IdentifierTokenizer $tokenizer,
    ): array {
        $findings = [];
        $symbol   = $this->symbol($scope);

        // Weigh each declared parameter.
        foreach ($scope->node->params as $param) {
            // Skip anything without a plain string name.
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $finding = $this->finding(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                node:         $param,
                identifier:   ['kind' => $param->flags === 0 ? 'parameter' : 'property', 'name' => $param->var->name, 'symbol' => $symbol],
                suffixes:     $suffixes,
                tokenizer:    $tokenizer,
                type:         $param->type,
            );

            // Keep the parameter only when its name carries a type suffix.
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Builds suffix-Hungarian findings for the local variables in one callable scope.
     *
     * @param RuleDefinition        $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit          $analysisUnit - Parsed unit that owns the callable scope.
     * @param FunctionLikeScope     $scope - Callable scope whose locals are inspected.
     * @param array<string, string> $suffixes - Map of lower-case suffix token to configured display suffix.
     * @param IdentifierTokenizer   $tokenizer - Splits names into tokens so trailing type suffixes can be isolated.
     *
     * @return list<Finding> - local variable suffix findings in discovery order
     */
    private function localVariableFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        array $suffixes,
        IdentifierTokenizer $tokenizer,
    ): array {
        $findings = [];
        $symbol   = $this->symbol($scope);

        // Weigh each local the scope declares.
        foreach ($scope->localVariables as $name => $variable) {
            $suffixToken = $this->suffixToken($name, $suffixes, $tokenizer);
            // Skip a name with no type suffix, or one whose local doc type disagrees with it.
            if ($suffixToken === null || !$this->allowsLocalTypeSuffix($variable, $suffixes[$suffixToken])) {
                continue;
            }

            $finding = $this->finding(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                node:         $variable,
                identifier:   ['kind' => 'variable', 'name' => $name, 'symbol' => $symbol],
                suffixes:     $suffixes,
                tokenizer:    $tokenizer,
                type:         null,
            );

            // Keep the local only when its name carries a type suffix.
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Builds a finding for an identifier whose name ends in a type suffix, unless the type contradicts it.
     *
     * @param RuleDefinition                                         $definition - Rule metadata supplying id, severity, pillar, and confidence for the finding.
     * @param AnalysisUnit                                           $analysisUnit - Parsed unit whose display path anchors the reported finding.
     * @param Node                                                   $node - Declaration node whose start line locates the finding.
     * @param array{kind: string, name: string, symbol: string|null} $identifier - Identifier kind (property/parameter/variable), bare name, and owning symbol.
     * @param array<string, string>                                  $suffixes - Map of lower-case suffix token to configured display suffix.
     * @param IdentifierTokenizer                                    $tokenizer - Splits the name into camel/Pascal tokens to isolate the trailing suffix.
     * @param Node|null                                              $type - Declared type to weigh against the suffix; null when no declaration constrains it.
     *
     * @return Finding|null - Finding for an identifier with a type suffix.
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
        // No configured suffix at the end of the name, so there is nothing to report.
        if ($suffixToken === null) {
            return null;
        }

        $suffix = $suffixes[$suffixToken];
        // A declared type that disagrees with the suffix means the name is not restating it.
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
     * Reports whether the local `@var` evidence, if any, leaves the suffix redundant.
     *
     * @param Variable $variable - Local variable node whose nearest `@var` annotation, if any, is consulted.
     * @param string   $suffix - Display suffix the name carries, compared case-insensitively against the doc type.
     *
     * @return bool - True when no local doc type contradicts the suffix.
     */
    private function allowsLocalTypeSuffix(Variable $variable, string $suffix): bool
    {
        $docType = $this->localVarDocType($variable);

        return $docType === null || $this->matchesDocTypeSuffix($docType, strtolower($suffix));
    }

    /**
     * Returns the configured suffix token at the end of a name, or null when there is none.
     *
     * @param string                $name - Identifier (without leading `$`) whose trailing token is examined.
     * @param array<string, string> $suffixes - Map of lower-case suffix token to configured display suffix.
     * @param IdentifierTokenizer   $tokenizer - Splits the name into tokens so the final word can be matched.
     *
     * @return string|null - Lower-case suffix token matched at the end of the name.
     */
    private function suffixToken(string $name, array $suffixes, IdentifierTokenizer $tokenizer): ?string
    {
        $tokens = $tokenizer->tokenize($name);
        // A single-word name, or a conversion idiom, carries no type suffix to flag.
        if (count($tokens) < 2 || $this->isConversionIdiom($tokens)) {
            return null;
        }

        $suffixToken = $tokens[array_key_last($tokens)];

        return isset($suffixes[$suffixToken]) ? $suffixToken : null;
    }

    /**
     * Reads the nearest local `@var` type attached to a variable assignment.
     *
     * @param Variable $variable - Local variable node; the walk climbs its `parent` chain looking for a `@var` doc.
     *
     * @return string|null - PHPDoc type text when present.
     */
    private function localVarDocType(Variable $variable): ?string
    {
        $parent = $variable->getAttribute('parent');

        // Walk outward from the variable looking for an attached doc type.
        while ($parent instanceof Node) {
            $docComment = $parent->getDocComment();
            // Read an adjacent @var type assertion before using it to infer suffix intent.
            if ($docComment !== null && preg_match('/@var\s+([^\s]+)/', $docComment->getText(), $matches) === 1) {
                return $matches[1];
            }

            // Stop at the enclosing statement or function; a doc further out is unrelated to this variable.
            if ($parent instanceof Expression || $parent instanceof ClassMethod || $parent instanceof Function_) {
                return null;
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * Reports whether a declared type exists and does not support the suffix.
     *
     * @param Node|null $type - Declared type node to test, or null when the declaration omits a type.
     * @param string    $suffix - Lower-case suffix token the name carries (e.g. `string`, `array`).
     *
     * @return bool - True when the declared type exists and does not support the suffix.
     */
    private function doesTypeContradictSuffix(?Node $type, string $suffix): bool
    {
        // An untyped declaration cannot contradict the suffix.
        if ($type === null) {
            return false;
        }

        $typeName = $this->singleTypeName($type);

        return $typeName !== null && !$this->matchesTypeNameSuffix($typeName, $suffix);
    }

    /**
     * Reports whether a single-arm PHPDoc type supports the configured suffix.
     *
     * @param string $type - Raw PHPDoc type text from a `@var` tag, possibly nullable or a union.
     * @param string $suffix - Lower-case suffix token to confirm against the type's sole non-null arm.
     *
     * @return bool - True when the PHPDoc type matches the suffix.
     */
    private function matchesDocTypeSuffix(string $type, string $suffix): bool
    {
        $normalised = trim($type, " \t\n\r\0\x0B?");
        $arms       = array_values(array_filter(
            preg_split('/\|/', $normalised) ?: [],
            static fn (string $arm): bool => strtolower(trim($arm)) !== 'null',
        ));

        // Only a single non-null arm can be judged against the suffix.
        if (count($arms) !== 1) {
            return false;
        }

        return $this->matchesTypeNameSuffix($arms[0], $suffix);
    }

    /**
     * Reports whether a native or short type name supports the configured suffix.
     *
     * @param string $typeName - Declared or PHPDoc type name; namespace, generics, and `[]` are stripped first.
     * @param string $suffix - Lower-case suffix token the identifier carries, naming the native type it claims to
     *                         be; the `match ($suffix)` expression is the authoritative set of recognised tokens and
     *                         the type names each one admits. An unrecognised token can never match (`default` arm).
     *
     * @return bool - True when the type name matches the suffix.
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
     * Resolves nullable or simple single-arm types to one type name.
     *
     * @param Node $type - Type-hint node from a declaration: nullable, plain, or union form.
     *
     * @return string|null - Type name when the declaration has exactly one non-null arm.
     */
    private function singleTypeName(Node $type): ?string
    {
        // Unwrap `?T` down to the inner type.
        if ($type instanceof NullableType) {
            return $this->singleTypeName($type->type);
        }

        // A plain type name is returned as-is.
        if ($type instanceof Identifier || $type instanceof Name) {
            return $type->toString();
        }

        // A union resolves only when it has exactly one non-null arm.
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
     * Normalises the configured suffixes to case-insensitive lookup keys.
     *
     * @param list<string> $suffixes - Configured display suffixes as authored in `.gruff-php.yaml`, any casing.
     *
     * @return array<string, string> - Lower-case token keyed to the original display suffix; blanks dropped.
     */
    private function normalisedSuffixes(array $suffixes): array
    {
        $normalised = [];

        // Key each configured suffix by its lowercase token.
        foreach ($suffixes as $suffix) {
            $token = strtolower($suffix);
            // Skip a blank suffix entry.
            if ($token !== '') {
                $normalised[$token] = $suffix;
            }
        }

        return $normalised;
    }

    /**
     * Reports whether the suffix is part of an explicit `as`/`to` conversion idiom.
     *
     * @param list<string> $tokens - Lower-case name tokens in source order; the last is the candidate suffix.
     *
     * @return bool - True when the suffix is part of an explicit conversion idiom.
     */
    private function isConversionIdiom(array $tokens): bool
    {
        // A conversion idiom needs at least a name, a connector, and the type token.
        if (count($tokens) < 3) {
            return false;
        }

        $previousToken = $tokens[array_key_last($tokens) - 1];

        return in_array($previousToken, ['as', 'to'], true);
    }

    /**
     * Resolves the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope - Scope being reported; its node and kind name the owning callable.
     *
     * @return string - Named callable symbol or synthetic closure/arrow label.
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        // Named callables resolve to their declared symbol.
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        // Closures and arrow functions have no name, so fall back to a kind@line label.
        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }
}
