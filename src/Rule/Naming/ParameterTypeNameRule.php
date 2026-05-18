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
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\UnionType;

/**
 * Detects class-typed parameters whose names do not match their type.
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
        'string',
        'true',
        'void',
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
            name:            'Parameter type name',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  [
                'typeSuffixesToTrim' => ['Interface'],
                'ignoredParameterNames' => [],
            ],
            description: 'Flags class-typed parameters whose variable name does not match the lower-camel type name. Configure ignoredParameterNames to exempt project-specific parameter names (e.g., AST-walker conventions like $node or $context).',
        );
    }

    /**
     * Find class-typed parameters whose names do not match their type names.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for mismatched parameter/type names.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition            = $this->definition();
        $settings              = $context->settingsFor($definition);
        $typeSuffixesToTrim    = $settings->stringListOption('typeSuffixesToTrim');
        $ignoredParameterNames = $settings->stringListOption('ignoredParameterNames');
        $tokenizer             = new IdentifierTokenizer();
        $findings              = [];

        foreach ((new FunctionLikeScopeWalker())->scopes($unit->statements) as $scope) {
            $symbol = $this->symbol($scope);

            foreach ($scope->node->params as $param) {
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

                $expectedName = $this->expectedParameterName($typeName, $tokenizer, $typeSuffixesToTrim);
                if ($expectedName === null || $param->var->name === $expectedName) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:  $definition->id,
                    message: sprintf(
                        'Parameter $%s in %s should be named $%s to match %s.',
                        $param->var->name,
                        $symbol,
                        $expectedName,
                        $typeName,
                    ),
                    filePath:    $unit->file->displayPath,
                    line:        $param->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $symbol,
                    remediation: sprintf('Rename $%s to $%s.', $param->var->name, $expectedName),
                    metadata:    [
                        'parameter' => $param->var->name,
                        'type' => $typeName,
                        'expectedName' => $expectedName,
                    ],
                );
            }
        }

        return $findings;
    }

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
     * @return string|null Expected lower-camel parameter name, or null for builtin types.
     */
    private function expectedParameterName(string $typeName, IdentifierTokenizer $tokenizer, array $typeSuffixesToTrim): ?string
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
