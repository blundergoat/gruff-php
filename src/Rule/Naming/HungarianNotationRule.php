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
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Detects variable names that encode type prefixes.
 */
final readonly class HungarianNotationRule implements RuleInterface
{
    /**
     * Stable identifier for the Hungarian notation rule.
     */
    public const ID = 'naming.hungarian-notation';

    /**
     * Type prefixes considered Hungarian notation in variable names.
     */
    private const PREFIXES = ['str', 'int', 'float', 'bool', 'arr', 'obj', 'fn', 'cls'];

    /**
     * Describe the Hungarian notation rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory by default and confidence Medium: a type-prefix match is suggestive, not proof of intent.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Hungarian notation',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  ['typePrefixes' => self::PREFIXES],
            description:     'Flags identifiers that duplicate type information with configured prefixes such as arr, obj, str, or bool.',
        );
    }

    /**
     * Find local variables that use type-prefix naming.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     * @return list<Finding> Findings for Hungarian notation variables.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $prefixes   = $this->normalisedPrefixes($ruleContext->settingsFor($definition)->stringListOption('typePrefixes'));
        $findings   = [];

        foreach ((new FunctionLikeScopeWalker())->scopes($analysisUnit->statements) as $scope) {
            array_push(
                $findings,
                ...$this->parameterFindings($definition, $analysisUnit, $scope, $prefixes),
                ...$this->localVariableFindings($definition, $analysisUnit, $scope, $prefixes),
            );
        }

        // Hand back every prefixed-parameter and prefixed-local finding gathered across all scopes.
        return $findings;
    }

    /**
     * Find Hungarian notation parameters in one function-like scope.
     *
     * @param RuleDefinition    $definition   Rule definition supplying severity, pillar, and ids for emitted findings.
     * @param AnalysisUnit      $analysisUnit Parsed unit, used for the finding's file path and line numbers.
     * @param FunctionLikeScope $scope        Single function-like scope whose declared parameters are inspected.
     * @param list<string>      $prefixes     Configured lowercase type prefixes.
     * @return list<Finding> Findings for prefixed parameters.
     */
    private function parameterFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        array $prefixes,
    ): array
    {
        $findings = [];
        $symbol   = $this->symbol($scope);

        foreach ($scope->node->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $finding = $this->finding(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                node:         $param,
                kind:         'parameter',
                name:         $param->var->name,
                symbol:       $symbol,
                prefixes:     $prefixes,
            );
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        // Hand back one finding per Hungarian-prefixed parameter in this scope.
        return $findings;
    }

    /**
     * Find Hungarian notation local variables in one function-like scope.
     *
     * @param RuleDefinition    $definition   Rule definition supplying severity, pillar, and ids for emitted findings.
     * @param AnalysisUnit      $analysisUnit Parsed unit, used for the finding's file path and line numbers.
     * @param FunctionLikeScope $scope        Single function-like scope whose collected local variables are inspected.
     * @param list<string>      $prefixes     Configured lowercase type prefixes.
     * @return list<Finding> Findings for prefixed local variables.
     */
    private function localVariableFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        array $prefixes,
    ): array
    {
        $findings = [];
        $symbol   = $this->symbol($scope);

        foreach ($scope->localVariables as $name => $variable) {
            $finding = $this->finding(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                node:         $variable,
                kind:         'variable',
                name:         $name,
                symbol:       $symbol,
                prefixes:     $prefixes,
            );
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        // Hand back one finding per Hungarian-prefixed local variable in this scope.
        return $findings;
    }

    /**
     * Build a Hungarian notation finding when the identifier matches a type prefix.
     *
     * @param RuleDefinition $definition   Rule definition supplying severity, pillar, and ids for the finding.
     * @param AnalysisUnit   $analysisUnit Parsed unit, source of the finding's file path.
     * @param Node           $node         AST node whose start line locates the offending identifier.
     * @param string         $kind         Identifier kind, either "parameter" or "variable"; surfaced in the message.
     * @param string         $name         Identifier text without the leading `$`, matched against the prefixes.
     * @param string         $symbol       Enclosing callable label shown to the reader in the finding message.
     * @param list<string>   $prefixes     Configured lowercase type prefixes.
     * @return Finding|null Finding for a prefixed identifier.
     */
    private function finding(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        Node $node,
        string $kind,
        string $name,
        string $symbol,
        array $prefixes,
    ): ?Finding {
        $prefix = $this->detectPrefix($name, $prefixes);

        if ($prefix === null) {
            // No configured prefix matched, so this identifier is clean and yields no finding.
            return null;
        }

        // The identifier opens with a type prefix; report it so the reader can drop the redundant tag.
        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s $%s in %s uses Hungarian notation prefix "%s".', ucfirst($kind), $name, $symbol, $prefix),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: sprintf('Remove the type prefix. Use $%s instead. If the prefix is a domain convention rather than Hungarian notation, remove it from `rules.naming.hungarian-notation.options.typePrefixes` in `.gruff-php.yaml`.', lcfirst(substr($name, strlen($prefix)))),
            metadata:    ['variable' => $name, 'prefix' => $prefix, 'identifierKind' => $kind],
        );
    }

    /**
     * Detect a configured type prefix followed by an uppercase boundary.
     *
     * @param string       $name     Identifier to test; a match needs the prefix plus an uppercase next character.
     * @param list<string> $prefixes Configured lowercase type prefixes.
     * @return string|null Matched prefix, or null when the name is acceptable.
     */
    private function detectPrefix(string $name, array $prefixes): ?string
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)
                && strlen($name) > strlen($prefix)
                && ctype_upper($name[strlen($prefix)])
            ) {
                // Prefix matches and the next character starts a new word, the Hungarian-notation shape.
                return $prefix;
            }
        }

        // No prefix bordered an uppercase boundary, so the name is not Hungarian notation.
        return null;
    }

    /**
     * @param list<string> $prefixes Configured type prefixes.
     * @return list<string> Lowercase type prefixes.
     */
    private function normalisedPrefixes(array $prefixes): array
    {
        // Lowercase and de-duplicate so prefix matching stays case-insensitive regardless of config casing.
        return array_values(array_unique(array_map(
            static fn (string $prefix): string => strtolower($prefix),
            $prefixes,
        )));
    }

    /**
     * Resolve the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope Scope to label; named callables resolve to their name, others to kind@line.
     * @return string Named callable symbol or synthetic closure/arrow label.
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            // Named callables get their declared symbol so the finding points at a recognisable place.
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        // Closures and arrow functions have no name, so fall back to a kind@line synthetic label.
        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }
}
