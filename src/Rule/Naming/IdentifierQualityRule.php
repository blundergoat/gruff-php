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
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

final readonly class IdentifierQualityRule implements RuleInterface
{
    public const ID = 'naming.identifier-quality';

    private const DEFAULT_PLACEHOLDER_NAMES = ['foo', 'bar', 'baz', 'tmp', 'temp', 'obj', 'arr'];
    private const DEFAULT_GENERIC_TOKENS = ['data', 'info', 'item', 'thing', 'stuff', 'helper', 'util'];
    private const DEFAULT_IGNORED_NAMES = [
        '_',
        'e',
        'exception',
        'throwable',
        'i',
        'j',
        'k',
        'idx',
        'index',
        'key',
        'value',
    ];
    private const MAGIC_METHODS = [
        '__construct',
        '__destruct',
        '__clone',
        '__toString',
        '__debugInfo',
        '__get',
        '__set',
        '__isset',
        '__unset',
        '__call',
        '__callStatic',
        '__invoke',
        '__sleep',
        '__wakeup',
        '__serialize',
        '__unserialize',
        '__set_state',
    ];
    private const LIFECYCLE_METHODS = [
        'setUp',
        'tearDown',
        'setUpBeforeClass',
        'tearDownAfterClass',
        'configure',
        'execute',
        'initialize',
        'interact',
    ];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Identifier quality',
            pillar: Pillar::Naming,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
            defaultOptions: [
                'placeholderNames' => self::DEFAULT_PLACEHOLDER_NAMES,
                'genericTokens' => self::DEFAULT_GENERIC_TOKENS,
                'ignoredNames' => self::DEFAULT_IGNORED_NAMES,
                'minScopeReferences' => 1,
            ],
            description: 'Catches placeholder, generic, and numbered identifiers that obscure intent.',
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings = $context->settingsFor($definition);
        $tokenizer = new IdentifierTokenizer();
        $finder = new NodeFinder();
        $placeholderNames = $this->lowercaseList($settings->stringListOption('placeholderNames'));
        $genericTokens = $this->lowercaseList($settings->stringListOption('genericTokens'));
        $ignoredNames = $this->lowercaseList($settings->stringListOption('ignoredNames'));
        $acceptedAbbreviations = $this->lowercaseList($context->config->acceptedAbbreviations());
        $minScopeOption = $settings->option('minScopeReferences');
        $minScopeReferences = is_int($minScopeOption) ? max(1, $minScopeOption) : 1;
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, ClassLike::class) as $node) {
            if (!$node instanceof Class_ && !$node instanceof Interface_ && !$node instanceof Trait_ && !$node instanceof Enum_) {
                continue;
            }

            $name = $node->name?->toString();
            if ($name === null) {
                continue;
            }

            $finding = $this->finding(
                definition: $definition,
                unit: $unit,
                node: $node,
                kind: $this->classLikeKind($node),
                name: $name,
                symbol: $name,
                tokenizer: $tokenizer,
                placeholderNames: $placeholderNames,
                genericTokens: $genericTokens,
                ignoredNames: $ignoredNames,
                acceptedAbbreviations: $acceptedAbbreviations,
            );

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        foreach ($finder->find($unit->statements, static fn (Node $node): bool => $node instanceof ClassMethod || $node instanceof Function_) as $function) {
            /** @var ClassMethod|Function_ $function */
            $symbol = CyclomaticComplexityRule::resolveSymbol($function);

            if (!$this->shouldSkipFunctionLike($function)) {
                $finding = $this->finding(
                    definition: $definition,
                    unit: $unit,
                    node: $function,
                    kind: $function instanceof ClassMethod ? 'method' : 'function',
                    name: $function->name->toString(),
                    symbol: $symbol,
                    tokenizer: $tokenizer,
                    placeholderNames: $placeholderNames,
                    genericTokens: $genericTokens,
                    ignoredNames: $ignoredNames,
                    acceptedAbbreviations: $acceptedAbbreviations,
                );

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }

            foreach ($function->params as $param) {
                if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                $paramKind = $param->flags === 0 ? 'parameter' : 'property';
                $finding = $this->finding(
                    definition: $definition,
                    unit: $unit,
                    node: $param,
                    kind: $paramKind,
                    name: $param->var->name,
                    symbol: $symbol,
                    tokenizer: $tokenizer,
                    placeholderNames: $placeholderNames,
                    genericTokens: $genericTokens,
                    ignoredNames: $ignoredNames,
                    acceptedAbbreviations: $acceptedAbbreviations,
                );

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }

            foreach ($this->localVariableNames($function, $finder, $minScopeReferences) as $name => $variable) {
                $finding = $this->finding(
                    definition: $definition,
                    unit: $unit,
                    node: $variable,
                    kind: 'variable',
                    name: $name,
                    symbol: $symbol,
                    tokenizer: $tokenizer,
                    placeholderNames: $placeholderNames,
                    genericTokens: $genericTokens,
                    ignoredNames: $ignoredNames,
                    acceptedAbbreviations: $acceptedAbbreviations,
                );

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        foreach ($finder->findInstanceOf($unit->statements, Property::class) as $property) {
            foreach ($property->props as $prop) {
                $name = $prop->name->toString();
                $finding = $this->finding(
                    definition: $definition,
                    unit: $unit,
                    node: $prop,
                    kind: 'property',
                    name: $name,
                    symbol: '$' . $name,
                    tokenizer: $tokenizer,
                    placeholderNames: $placeholderNames,
                    genericTokens: $genericTokens,
                    ignoredNames: $ignoredNames,
                    acceptedAbbreviations: $acceptedAbbreviations,
                );

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $placeholderNames
     * @param list<string> $genericTokens
     * @param list<string> $ignoredNames
     * @param list<string> $acceptedAbbreviations
     */
    private function finding(
        RuleDefinition $definition,
        AnalysisUnit $unit,
        Node $node,
        string $kind,
        string $name,
        ?string $symbol,
        IdentifierTokenizer $tokenizer,
        array $placeholderNames,
        array $genericTokens,
        array $ignoredNames,
        array $acceptedAbbreviations,
    ): ?Finding {
        if ($this->isIgnored($name, $ignoredNames, $acceptedAbbreviations)) {
            return null;
        }

        $tokens = $tokenizer->tokenize($name);
        if ($tokens === []) {
            return null;
        }

        $variant = null;
        $matchedToken = null;
        $lowerName = strtolower(ltrim($name, '$'));

        if (in_array($lowerName, $placeholderNames, true)) {
            $variant = 'placeholder';
            $matchedToken = $lowerName;
        } elseif ($this->allTokensMatch($tokens, $genericTokens)) {
            $variant = 'generic';
            $matchedToken = implode(' ', $tokens);
        } elseif ($this->isNumberedIdentifier($name, $tokens, $genericTokens, $placeholderNames, $acceptedAbbreviations)) {
            $variant = 'numbered';
            $matchedToken = $tokens[array_key_last($tokens)];
        }

        if ($variant === null) {
            return null;
        }

        return new Finding(
            ruleId: $definition->id,
            message: sprintf('%s name "%s" is %s and does not communicate clear intent.', ucfirst($kind), $name, $variant),
            filePath: $unit->file->displayPath,
            line: $node->getStartLine(),
            severity: $definition->defaultSeverity,
            pillar: $definition->pillar,
            tier: $definition->tier,
            confidence: $definition->confidence,
            symbol: $symbol,
            remediation: 'Rename the identifier to describe its domain role or action.',
            metadata: [
                'identifierKind' => $kind,
                'identifierName' => $name,
                'variant' => $variant,
                'tokens' => $tokens,
                'matchedToken' => $matchedToken,
            ],
        );
    }

    /**
     * @param list<string> $ignoredNames
     * @param list<string> $acceptedAbbreviations
     */
    private function isIgnored(string $name, array $ignoredNames, array $acceptedAbbreviations): bool
    {
        $lowerName = strtolower($name);

        if (str_starts_with($name, '_')) {
            return true;
        }

        if (in_array($lowerName, $ignoredNames, true)) {
            return true;
        }

        return in_array($lowerName, $acceptedAbbreviations, true);
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $genericTokens
     */
    private function allTokensMatch(array $tokens, array $genericTokens): bool
    {
        foreach ($tokens as $token) {
            if (!in_array($token, $genericTokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $genericTokens
     * @param list<string> $placeholderNames
     * @param list<string> $acceptedAbbreviations
     */
    private function isNumberedIdentifier(
        string $name,
        array $tokens,
        array $genericTokens,
        array $placeholderNames,
        array $acceptedAbbreviations,
    ): bool {
        if (count($tokens) < 2 || !ctype_digit($tokens[array_key_last($tokens)])) {
            return false;
        }

        $prefixTokens = array_slice($tokens, 0, -1);
        $prefix = implode('', $prefixTokens);

        if (in_array($prefix, $acceptedAbbreviations, true)) {
            return false;
        }

        if (preg_match('/[A-Z]{2,}\d+$/', $name) === 1) {
            return false;
        }

        return in_array($prefix, $placeholderNames, true) || $this->allTokensMatch($prefixTokens, $genericTokens);
    }

    private function shouldSkipFunctionLike(ClassMethod|Function_ $node): bool
    {
        $name = $node->name->toString();

        if ($node instanceof ClassMethod && in_array($name, self::MAGIC_METHODS, true)) {
            return true;
        }

        if ($node instanceof ClassMethod && in_array($name, self::LIFECYCLE_METHODS, true)) {
            return true;
        }

        return str_starts_with($name, 'provide') || str_ends_with($name, 'Provider');
    }

    /**
     * @return array<string, Variable>
     */
    private function localVariableNames(ClassMethod|Function_ $function, NodeFinder $finder, int $minScopeReferences): array
    {
        $variables = [];
        $counts = [];
        $loopVars = $this->loopVariables($function, $finder);
        $catchVars = $this->catchVariables($function, $finder);
        $parameterNames = [];

        foreach ($function->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $parameterNames[$param->var->name] = true;
            }
        }

        foreach ($finder->findInstanceOf($function->stmts ?? [], Variable::class) as $variable) {
            if (!is_string($variable->name)) {
                continue;
            }

            $name = $variable->name;
            if (isset($parameterNames[$name]) || isset($loopVars[$name]) || isset($catchVars[$name])) {
                continue;
            }

            $counts[$name] = ($counts[$name] ?? 0) + 1;
            $variables[$name] ??= $variable;
        }

        return array_filter(
            $variables,
            static fn (Variable $variable, string $name): bool => ($counts[$name] ?? 0) >= $minScopeReferences,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<string, true>
     */
    private function loopVariables(ClassMethod|Function_ $function, NodeFinder $finder): array
    {
        $variables = [];

        foreach ($finder->find($function->stmts ?? [], static fn (Node $node): bool => $node instanceof For_ || $node instanceof Foreach_) as $loop) {
            if ($loop instanceof For_) {
                foreach ($finder->findInstanceOf($loop->init, Variable::class) as $variable) {
                    if (is_string($variable->name)) {
                        $variables[$variable->name] = true;
                    }
                }
            }

            if ($loop instanceof Foreach_) {
                foreach ([$loop->keyVar, $loop->valueVar] as $variable) {
                    if ($variable instanceof Variable && is_string($variable->name)) {
                        $variables[$variable->name] = true;
                    }
                }
            }
        }

        return $variables;
    }

    /**
     * @return array<string, true>
     */
    private function catchVariables(ClassMethod|Function_ $function, NodeFinder $finder): array
    {
        $variables = [];

        foreach ($finder->findInstanceOf($function->stmts ?? [], Catch_::class) as $catch) {
            if ($catch->var instanceof Variable && is_string($catch->var->name)) {
                $variables[$catch->var->name] = true;
            }
        }

        return $variables;
    }

    private function classLikeKind(Class_|Interface_|Trait_|Enum_ $node): string
    {
        return match (true) {
            $node instanceof Interface_ => 'interface',
            $node instanceof Trait_ => 'trait',
            $node instanceof Enum_ => 'enum',
            default => 'class',
        };
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function lowercaseList(array $values): array
    {
        return array_values(array_unique(array_map(
            static fn (string $value): string => strtolower($value),
            $values,
        )));
    }
}
