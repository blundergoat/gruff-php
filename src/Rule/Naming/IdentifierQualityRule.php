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

/**
 * Detects placeholder, generic, and numbered identifiers that obscure intent.
 */
final readonly class IdentifierQualityRule implements RuleInterface
{
    /**
     * Stable identifier for the identifier quality rule.
     */
    public const ID = 'naming.identifier-quality';

    /**
     * Placeholder names that rarely communicate domain intent.
     */
    private const DEFAULT_PLACEHOLDER_NAMES = ['foo', 'bar', 'baz', 'tmp', 'temp', 'obj', 'arr'];

    /**
     * Generic tokens that need enough scope usage to be acceptable.
     */
    private const DEFAULT_GENERIC_TOKENS = ['data', 'info', 'item', 'thing', 'stuff', 'helper', 'util'];

    /**
     * Short conventional names ignored by the identifier quality rule.
     */
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

    /**
     * PHP magic methods exempt from generic method-name checks.
     */
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

    /**
     * Framework and lifecycle hooks exempt from generic method-name checks.
     */
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

    /**
     * Describe the identifier quality rule.
     *
     * @return RuleDefinition Rule metadata, defaults, and options.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Identifier quality',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  [
                'placeholderNames' => self::DEFAULT_PLACEHOLDER_NAMES,
                'genericTokens' => self::DEFAULT_GENERIC_TOKENS,
                'ignoredNames' => self::DEFAULT_IGNORED_NAMES,
                'minScopeReferences' => 1,
            ],
            description: 'Catches placeholder, generic, and numbered identifiers that obscure intent.',
        );
    }

    /**
     * Find placeholder, generic, and numbered identifiers across declarations and locals.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for low-quality identifiers.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition     = $this->definition();
        $finder         = new NodeFinder();
        $findingContext = $this->findingContext($unit, $context, $definition);

        return [
            ...$this->classLikeFindings($findingContext, $finder),
            ...$this->functionLikeFindings(
                findingContext:     $findingContext,
                finder:             $finder,
                minScopeReferences: $this->minScopeReferences($context, $definition),
            ),
            ...$this->propertyFindings($findingContext, $finder),
        ];
    }

    /**
     * Build shared finding inputs from rule settings.
     *
     * @return IdentifierFindingContext Shared context for identifier finding checks.
     */
    private function findingContext(
        AnalysisUnit $unit,
        RuleContext $context,
        RuleDefinition $definition,
    ): IdentifierFindingContext {
        $settings = $context->settingsFor($definition);

        return new IdentifierFindingContext(
            definition:            $definition,
            unit:                  $unit,
            tokenizer:             new IdentifierTokenizer(),
            placeholderNames:      $this->lowercaseList($settings->stringListOption('placeholderNames')),
            genericTokens:         $this->lowercaseList($settings->stringListOption('genericTokens')),
            ignoredNames:          $this->lowercaseList($settings->stringListOption('ignoredNames')),
            acceptedAbbreviations: $this->lowercaseList($context->config->acceptedAbbreviations()),
        );
    }

    /**
     * Resolve the minimum local-variable reference count needed before reporting.
     *
     * @return int Minimum number of local variable reads.
     */
    private function minScopeReferences(RuleContext $context, RuleDefinition $definition): int
    {
        $minScopeOption = $context->settingsFor($definition)->option('minScopeReferences');

        return is_int($minScopeOption) ? max(1, $minScopeOption) : 1;
    }

    /**
     * Find low-quality class, interface, trait, and enum names.
     *
     * @return list<Finding> Findings for class-like identifiers.
     */
    private function classLikeFindings(IdentifierFindingContext $findingContext, NodeFinder $finder): array
    {
        $findings = [];

        foreach ($finder->findInstanceOf($findingContext->unit->statements, ClassLike::class) as $node) {
            if (!$node instanceof Class_ && !$node instanceof Interface_ && !$node instanceof Trait_ && !$node instanceof Enum_) {
                continue;
            }

            $name = $node->name?->toString();
            if ($name === null) {
                continue;
            }

            $finding = $this->finding(
                context: $findingContext,
                node:    $node,
                kind:    $this->classLikeKind($node),
                name:    $name,
                symbol:  $name,
            );

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Find low-quality function-like names, parameters, and local variables.
     *
     * @return list<Finding> Findings for function-like identifier scopes.
     */
    private function functionLikeFindings(
        IdentifierFindingContext $findingContext,
        NodeFinder $finder,
        int $minScopeReferences,
    ): array {
        $findings = [];

        foreach ($finder->find($findingContext->unit->statements, static fn (Node $node): bool => $node instanceof ClassMethod || $node instanceof Function_) as $function) {
            /** @var ClassMethod|Function_ $function Finder predicate restricts results to function-like nodes. */
            array_push(
                $findings,
                ...$this->functionNameFindings($findingContext, $function),
                ...$this->parameterFindings($findingContext, $function),
                ...$this->localVariableFindings($findingContext, $function, $finder, $minScopeReferences),
            );
        }

        return $findings;
    }

    /**
     * Find a low-quality method or function name.
     *
     * @return list<Finding> Empty when the function-like name is exempt or acceptable.
     */
    private function functionNameFindings(IdentifierFindingContext $findingContext, ClassMethod|Function_ $function): array
    {
        if ($this->shouldSkipFunctionLike($function)) {
            return [];
        }

        $finding = $this->finding(
            context: $findingContext,
            node:    $function,
            kind:    $function instanceof ClassMethod ? 'method' : 'function',
            name:    $function->name->toString(),
            symbol:  CyclomaticComplexityRule::resolveSymbol($function),
        );

        return $finding instanceof Finding ? [$finding] : [];
    }

    /**
     * Find low-quality parameter and promoted-property names in one function-like scope.
     *
     * @return list<Finding> Findings for parameters and promoted properties.
     */
    private function parameterFindings(IdentifierFindingContext $findingContext, ClassMethod|Function_ $function): array
    {
        $findings = [];
        $symbol   = CyclomaticComplexityRule::resolveSymbol($function);

        foreach ($function->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $finding = $this->finding(
                context: $findingContext,
                node:    $param,
                kind:    $param->flags === 0 ? 'parameter' : 'property',
                name:    $param->var->name,
                symbol:  $symbol,
            );

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Find low-quality local variable names in one function-like scope.
     *
     * @return list<Finding> Findings for local variables.
     */
    private function localVariableFindings(
        IdentifierFindingContext $findingContext,
        ClassMethod|Function_ $function,
        NodeFinder $finder,
        int $minScopeReferences,
    ): array {
        $findings = [];
        $symbol   = CyclomaticComplexityRule::resolveSymbol($function);

        foreach ($this->localVariableNames($function, $finder, $minScopeReferences) as $name => $variable) {
            $finding = $this->finding(
                context: $findingContext,
                node:    $variable,
                kind:    'variable',
                name:    $name,
                symbol:  $symbol,
            );

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Find low-quality declared property names.
     *
     * @return list<Finding> Findings for property identifiers.
     */
    private function propertyFindings(IdentifierFindingContext $findingContext, NodeFinder $finder): array
    {
        $findings = [];

        foreach ($finder->findInstanceOf($findingContext->unit->statements, Property::class) as $property) {
            foreach ($property->props as $prop) {
                $name    = $prop->name->toString();
                $finding = $this->finding(
                    context: $findingContext,
                    node:    $prop,
                    kind:    'property',
                    name:    $name,
                    symbol:  '$' . $name,
                );

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @return Finding|null Identifier finding, or null when the name is acceptable/ignored.
     */
    private function finding(
        IdentifierFindingContext $context,
        Node $node,
        string $kind,
        string $name,
        ?string $symbol,
    ): ?Finding {
        if ($this->isIgnored($name, $context->ignoredNames, $context->acceptedAbbreviations)) {
            return null;
        }

        $tokens = $context->tokenizer->tokenize($name);
        if ($tokens === []) {
            return null;
        }

        $variant      = null;
        $matchedToken = null;
        $lowerName    = strtolower(ltrim($name, '$'));

        if (in_array($lowerName, $context->placeholderNames, true)) {
            $variant      = 'placeholder';
            $matchedToken = $lowerName;
        } elseif ($this->allTokensMatch($tokens, $context->genericTokens)) {
            $variant      = 'generic';
            $matchedToken = implode(' ', $tokens);
        } elseif ($this->isNumberedIdentifier($name, $tokens, $context->genericTokens, $context->placeholderNames, $context->acceptedAbbreviations)) {
            $variant      = 'numbered';
            $matchedToken = $tokens[array_key_last($tokens)];
        }

        if ($variant === null) {
            return null;
        }

        return new Finding(
            ruleId:      $context->definition->id,
            message:     sprintf('%s name "%s" is %s and does not communicate clear intent.', ucfirst($kind), $name, $variant),
            filePath:    $context->unit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $context->definition->defaultSeverity,
            pillar:      $context->definition->pillar,
            tier:        $context->definition->tier,
            confidence:  $context->definition->confidence,
            symbol:      $symbol,
            remediation: 'Rename the identifier to describe its domain role or action.',
            metadata:    [
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
     *
     * @return bool True when the name should be skipped by this rule.
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
     *
     * @return bool True when every token is a configured generic token.
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
     *
     * @return bool True when the identifier is a weak numbered variant.
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
        $prefix       = implode('', $prefixTokens);

        if (in_array($prefix, $acceptedAbbreviations, true)) {
            return false;
        }

        if (preg_match('/[A-Z]{2,}\d+$/', $name) === 1) {
            return false;
        }

        return in_array($prefix, $placeholderNames, true) || $this->allTokensMatch($prefixTokens, $genericTokens);
    }

    /**
     * Skip framework lifecycle and test data-provider function-like declarations.
     *
     * @return bool True when the function-like node should not be checked.
     */
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
        $variables      = [];
        $counts         = [];
        $loopVars       = $this->loopVariables($function, $finder);
        $catchVars      = $this->catchVariables($function, $finder);
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

    /**
     * Return the declaration kind for a class-like node.
     *
     * @return string One of class, interface, trait, or enum.
     */
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
