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
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\UnionType;

/**
 * Detects class-typed parameters and direct object locals whose names do not match their type.
 */
final readonly class ParameterTypeNameRule implements RuleInterface
{
    /**
     * Stable identifier for the parameter type-name rule.
     */
    public const ID = 'naming.parameter-type-name';

    /**
     * Native PHP type names excluded from class-name matching.
     */
    private const BUILTIN_TYPES = [
        'array',
        'bool',
        'callable',
        'false',
        'float',
        'int',
        'iterable',
        'mixed',
        'never',
        'null',
        'object',
        'parent',
        'self',
        'static',
        'stdclass',
        'string',
        'true',
        'void',
        'weakmap',
    ];

    /**
     * Describe the parameter type name rule.
     *
     * @return RuleDefinition Rule metadata, defaults, and options.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Parameter and variable type name',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  [
                'typeSuffixesToTrim' => ['Interface'],
                'ignoredParameterNames' => [],
            ],
            description: 'Flags class-typed parameters and direct object locals whose variable name does not match the lower-camel type name. Configure ignoredParameterNames to exempt project-specific parameter names (e.g., AST-walker conventions like $node or $context).',
        );
    }

    /**
     * Find class-typed parameters and direct object locals whose names do not match their type names.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for mismatched variable/type names.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition            = $this->definition();
        $settings              = $ruleContext->settingsFor($definition);
        $typeSuffixesToTrim    = $settings->stringListOption('typeSuffixesToTrim');
        $ignoredParameterNames = $settings->stringListOption('ignoredParameterNames');
        $identifierTokenizer   = new IdentifierTokenizer();
        $findings              = [];

        foreach ((new FunctionLikeScopeWalker())->scopes($analysisUnit->statements) as $scope) {
            $symbol             = $this->symbol($scope);
            $expectedByPosition = [];
            $expectedCounts     = [];

            foreach ($scope->node->params as $position => $param) {
                if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                if (in_array($param->var->name, $ignoredParameterNames, true)) {
                    continue;
                }

                $typeName = $this->shortTypeName($param->type);
                if ($typeName === null) {
                    continue;
                }

                $expectedName = $this->expectedVariableName($typeName, $identifierTokenizer, $typeSuffixesToTrim);
                if ($expectedName === null) {
                    continue;
                }

                $expectedByPosition[$position] = [$param, $typeName, $expectedName];
                $expectedCounts[$expectedName] = ($expectedCounts[$expectedName] ?? 0) + 1;
            }

            foreach ($expectedByPosition as [$param, $typeName, $expectedName]) {
                $parameterName = $param->var instanceof Variable && is_string($param->var->name)
                    ? $param->var->name
                    : '';

                if (
                    $parameterName === $expectedName
                    || $this->isSpecificDuplicateName($parameterName, $expectedName, $expectedCounts[$expectedName], $identifierTokenizer)
                ) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:  $definition->id,
                    message: sprintf(
                        'Parameter $%s in %s should be named $%s to match %s.',
                        $parameterName,
                        $symbol,
                        $expectedName,
                        $typeName,
                    ),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $param->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $symbol,
                    remediation: sprintf('Rename $%s to $%s.', $parameterName, $expectedName),
                    metadata:    [
                        'parameter' => $parameterName,
                        'type' => $typeName,
                        'expectedName' => $expectedName,
                    ],
                );
            }

            array_push(
                $findings,
                ...$this->localObjectFindings(
                    definition:          $definition,
                    analysisUnit:        $analysisUnit,
                    scope:               $scope,
                    symbol:              $symbol,
                    identifierTokenizer: $identifierTokenizer,
                    typeSuffixesToTrim:  $typeSuffixesToTrim,
                ),
            );
        }

        return $findings;
    }

    /**
     * Find direct `new Type()` assignments whose variable name does not match the type.
     *
     * @param list<string> $typeSuffixesToTrim
     * @return list<Finding>
     */
    private function localObjectFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        string $symbol,
        IdentifierTokenizer $identifierTokenizer,
        array $typeSuffixesToTrim,
    ): array {
        $expectedByVariableName = [];
        $expectedCounts         = [];

        foreach ($this->localObjectAssignments($scope) as [$variable, $typeName]) {
            $variableName = $variable->name;
            if (!is_string($variableName) || isset($expectedByVariableName[$variableName])) {
                continue;
            }

            $expectedName = $this->expectedVariableName($typeName, $identifierTokenizer, $typeSuffixesToTrim);
            if ($expectedName === null) {
                continue;
            }

            $expectedByVariableName[$variableName] = [$variable, $typeName, $expectedName];
            $expectedCounts[$expectedName]         = ($expectedCounts[$expectedName] ?? 0) + 1;
        }

        $findings = [];

        foreach ($expectedByVariableName as $variableName => [$variable, $typeName, $expectedName]) {
            if (
                $variableName === $expectedName
                || $this->isSpecificDuplicateName($variableName, $expectedName, $expectedCounts[$expectedName], $identifierTokenizer)
            ) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    'Variable $%s in %s should be named $%s to match %s.',
                    $variableName,
                    $symbol,
                    $expectedName,
                    $typeName,
                ),
                filePath:    $analysisUnit->file->displayPath,
                line:        $variable->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: sprintf('Rename $%s to $%s.', $variableName, $expectedName),
                metadata:    [
                    'identifierKind' => 'variable',
                    'variable' => $variableName,
                    'type' => $typeName,
                    'expectedName' => $expectedName,
                ],
            );
        }

        return $findings;
    }

    /**
     * Collect local object assignments that affect the naming rule.
     *
     * @return list<array{0: Variable, 1: string}>
     */
    private function localObjectAssignments(FunctionLikeScope $scope): array
    {
        $assignments = [];

        foreach ($this->nodesInScope($scope) as $node) {
            if (!$node instanceof Assign || !$node->var instanceof Variable || !$node->expr instanceof New_) {
                continue;
            }

            $typeName = $this->shortNewTypeName($node->expr);
            if ($typeName === null) {
                continue;
            }

            $assignments[] = [$node->var, $typeName];
        }

        return $assignments;
    }

    /**
     * List descendant nodes in the current function-like scope.
     *
     * @return list<Node>
     */
    private function nodesInScope(FunctionLikeScope $scope): array
    {
        $nodes = [];

        foreach ($this->bodyNodes($scope->node) as $bodyNode) {
            $this->collectScopeNode($bodyNode, $nodes);
        }

        return $nodes;
    }

    /**
     * Append a scope node and its descendants to the collection.
     *
     * @param list<Node> $nodes
     * @return void
     */
    private function collectScopeNode(Node $node, array &$nodes): void
    {
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            return;
        }

        $nodes[] = $node;

        foreach ($node->getSubNodeNames() as $name) {
            $this->collectChildNodes($node->{$name}, $nodes);
        }
    }

    /**
     * Append traversable child nodes to the current collection.
     *
     * @param list<Node> $nodes
     * @return void
     */
    private function collectChildNodes(mixed $subNode, array &$nodes): void
    {
        if ($subNode instanceof Node) {
            $this->collectScopeNode($subNode, $nodes);

            return;
        }

        if (!is_array($subNode)) {
            return;
        }

        foreach ($subNode as $childSubNode) {
            $this->collectChildNodes($childSubNode, $nodes);
        }
    }

    /**
     * List body statements for a function, method, or closure.
     *
     * @return list<Node>
     */
    private function bodyNodes(ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        if ($node instanceof ArrowFunction) {
            return [$node->expr];
        }

        return array_values($node->stmts ?? []);
    }

    /**
     * @return string|null Short class name, or null for unsupported `new` expressions.
     */
    private function shortNewTypeName(New_ $newExpression): ?string
    {
        if (!$newExpression->class instanceof Name) {
            return null;
        }

        $parts = $newExpression->class->getParts();

        return $parts[array_key_last($parts)] ?? null;
    }

    /**
     * Allow duplicate same-type parameters to carry both role and type words.
     *
     * @return bool True when a duplicate type name is still present in a more specific parameter name.
     */
    private function isSpecificDuplicateName(
        string $parameterName,
        string $expectedName,
        int $expectedCount,
        IdentifierTokenizer $tokenizer,
    ): bool {
        if ($expectedCount < 2) {
            return false;
        }

        $parameterTokens = $tokenizer->tokenize($parameterName);
        $expectedTokens  = $tokenizer->tokenize($expectedName);

        if (count($parameterTokens) <= count($expectedTokens) || $expectedTokens === []) {
            return false;
        }

        return $this->containsTokenSequence($parameterTokens, $expectedTokens);
    }

    /**
     * @param list<string> $haystack Identifier tokens to scan.
     * @param list<string> $needle   Expected type-name tokens.
     *
     * @return bool True when all expected tokens appear contiguously.
     */
    private function containsTokenSequence(array $haystack, array $needle): bool
    {
        $needleCount = count($needle);

        for ($offset = 0, $max = count($haystack) - $needleCount; $offset <= $max; $offset++) {
            if (array_slice($haystack, $offset, $needleCount) === $needle) {
                return true;
            }
        }

        return false;
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

    /**
     * Extract a short type name from nullable, named, identifier, or simple union types.
     *
     * @return string|null Short type name, or null when the type is unsupported.
     */
    private function shortTypeName(?Node $type): ?string
    {
        if ($type instanceof NullableType) {
            return $this->shortTypeName($type->type);
        }

        if ($type instanceof Name) {
            $parts = $type->getParts();

            return $parts[array_key_last($parts)] ?? null;
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof UnionType) {
            $nonNull = array_values(array_filter(
                $type->types,
                static fn (Node $node): bool => !($node instanceof Identifier && $node->toLowerString() === 'null'),
            ));

            if (count($nonNull) !== 1) {
                return null;
            }

            $arm = $nonNull[0];
            if (!$arm instanceof Name && !$arm instanceof Identifier) {
                return null;
            }

            return $this->shortTypeName($arm);
        }

        return null;
    }

    /**
     * @param list<string> $typeSuffixesToTrim
     *
     * @return string|null Expected lower-camel variable name, or null for builtin types.
     */
    private function expectedVariableName(string $typeName, IdentifierTokenizer $tokenizer, array $typeSuffixesToTrim): ?string
    {
        if (in_array(strtolower($typeName), self::BUILTIN_TYPES, true)) {
            return null;
        }

        foreach ($typeSuffixesToTrim as $suffix) {
            if (!str_ends_with($typeName, $suffix) || strlen($typeName) <= strlen($suffix)) {
                continue;
            }

            $typeName = substr($typeName, 0, -strlen($suffix));
            break;
        }

        $tokens = $tokenizer->tokenize($typeName);
        if ($tokens === []) {
            return null;
        }

        $first = array_shift($tokens);

        return $first . implode('', array_map(static fn (string $token): string => ucfirst($token), $tokens));
    }
}
