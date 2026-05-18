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
        return new RuleDefinition(
            id:              self::ID,
            name:            'Hungarian notation',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find local variables that use type-prefix naming.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     * @return list<Finding> Findings for Hungarian notation variables.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $findings   = [];

        foreach ((new FunctionLikeScopeWalker())->scopes($unit->statements) as $scope) {
            array_push(
                $findings,
                ...$this->parameterFindings($definition, $unit, $scope),
                ...$this->localVariableFindings($definition, $unit, $scope),
            );
        }

        return $findings;
    }

    /**
     * Find Hungarian notation parameters in one function-like scope.
     *
     * @return list<Finding> Findings for prefixed parameters.
     */
    private function parameterFindings(RuleDefinition $definition, AnalysisUnit $unit, FunctionLikeScope $scope): array
    {
        $findings = [];
        $symbol   = $this->symbol($scope);

        foreach ($scope->node->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $finding = $this->finding(
                definition: $definition,
                unit:       $unit,
                node:       $param,
                kind:       'parameter',
                name:       $param->var->name,
                symbol:     $symbol,
            );
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Find Hungarian notation local variables in one function-like scope.
     *
     * @return list<Finding> Findings for prefixed local variables.
     */
    private function localVariableFindings(RuleDefinition $definition, AnalysisUnit $unit, FunctionLikeScope $scope): array
    {
        $findings = [];
        $symbol   = $this->symbol($scope);

        foreach ($scope->localVariables as $name => $variable) {
            $finding = $this->finding(
                definition: $definition,
                unit:       $unit,
                node:       $variable,
                kind:       'variable',
                name:       $name,
                symbol:     $symbol,
            );
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Build a Hungarian notation finding when the identifier matches a type prefix.
     *
     * @return Finding|null Finding for a prefixed identifier.
     */
    private function finding(
        RuleDefinition $definition,
        AnalysisUnit $unit,
        Node $node,
        string $kind,
        string $name,
        string $symbol,
    ): ?Finding {
        $prefix = $this->detectPrefix($name);

        if ($prefix === null) {
            return null;
        }

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s $%s in %s uses Hungarian notation prefix "%s".', ucfirst($kind), $name, $symbol, $prefix),
            filePath:    $unit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: sprintf('Remove the type prefix. Use $%s instead.', lcfirst(substr($name, strlen($prefix)))),
            metadata:    ['variable' => $name, 'prefix' => $prefix, 'identifierKind' => $kind],
        );
    }

    /**
     * Detect a configured type prefix followed by an uppercase boundary.
     *
     * @return string|null Matched prefix, or null when the name is acceptable.
     */
    private function detectPrefix(string $name): ?string
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)
                && strlen($name) > strlen($prefix)
                && ctype_upper($name[strlen($prefix)])
            ) {
                return $prefix;
            }
        }

        return null;
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
