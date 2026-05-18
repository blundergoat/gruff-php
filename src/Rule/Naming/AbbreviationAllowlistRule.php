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
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

/**
 * Requires short lowercase abbreviations to be declared in project config.
 *
 * M51 keeps this separate from ShortVariableRule: single-character variables are
 * a local clarity problem, while 2-5 character abbreviations need an explicit
 * project vocabulary through allowlists.acceptedAbbreviations.
 */
final readonly class AbbreviationAllowlistRule implements RuleInterface
{
    /** Stable identifier for the abbreviation allowlist rule. */
    public const ID = 'naming.abbreviation-allowlist';

    /** Names that should never be treated as project abbreviations. */
    private const DEFAULT_IGNORED_NAMES = ['this'];

    /**
     * Describe the abbreviation allowlist rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Abbreviation allowlist',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            defaultOptions:  ['ignoredNames' => self::DEFAULT_IGNORED_NAMES, 'minLength' => 2, 'maxLength' => 3],
            description:     'Flags short lowercase identifiers that are not declared in acceptedAbbreviations.',
        );
    }

    /**
     * Find undeclared lowercase abbreviations on properties, parameters, and locals.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context carrying accepted abbreviations.
     * @return list<Finding> Findings for undeclared abbreviations.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $ignored    = $this->lowercaseList($context->settingsFor($definition)->stringListOption('ignoredNames'));
        $minLength  = $this->lengthOption($context, $definition, 'minLength', 2);
        $maxLength  = $this->lengthOption($context, $definition, 'maxLength', 3);
        $accepted   = $this->lowercaseList($context->config->acceptedAbbreviations());
        $finder     = new NodeFinder();
        $findings   = [];

        foreach ($finder->findInstanceOf($unit->statements, Property::class) as $property) {
            foreach ($property->props as $prop) {
                $finding = $this->finding($definition, $unit, $prop, 'property', $prop->name->toString(), '$' . $prop->name->toString(), $ignored, $accepted, $minLength, $maxLength);
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        foreach ((new FunctionLikeScopeWalker())->scopes($unit->statements) as $scope) {
            $symbol = $this->symbol($scope);
            foreach ($scope->node->params as $param) {
                if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                $finding = $this->finding($definition, $unit, $param, 'parameter', $param->var->name, $symbol, $ignored, $accepted, $minLength, $maxLength);
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }

            $exemptLocals = $this->exemptLocalNames($scope->node, $finder);
            foreach ($scope->localVariables as $name => $variable) {
                if (isset($exemptLocals[$name])) {
                    continue;
                }

                $finding = $this->finding($definition, $unit, $variable, 'variable', $name, $symbol, $ignored, $accepted, $minLength, $maxLength);
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $ignored
     * @param list<string> $accepted
     */
    private function finding(
        RuleDefinition $definition,
        AnalysisUnit $unit,
        Node $node,
        string $kind,
        string $name,
        ?string $symbol,
        array $ignored,
        array $accepted,
        int $minLength,
        int $maxLength,
    ): ?Finding {
        $lowerName = strtolower($name);
        if ($lowerName !== $name || !preg_match('/^[a-z]+$/', $name)) {
            return null;
        }

        $length = strlen($name);
        if ($length < $minLength || $length > $maxLength) {
            return null;
        }

        if (in_array($lowerName, $ignored, true) || in_array($lowerName, $accepted, true)) {
            return null;
        }

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s "$%s" is a short lowercase name not declared in acceptedAbbreviations.', ucfirst($kind), $name),
            filePath:    $unit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Rename the identifier or add the abbreviation to allowlists.acceptedAbbreviations with a documented meaning.',
            metadata:    ['identifierKind' => $kind, 'identifierName' => $name, 'minLength' => $minLength, 'maxLength' => $maxLength],
        );
    }

    private function lengthOption(RuleContext $context, RuleDefinition $definition, string $name, int $default): int
    {
        $value = $context->settingsFor($definition)->option($name);

        return is_int($value) ? max(1, $value) : $default;
    }

    /**
     * @return array<string, true>
     */
    private function exemptLocalNames(ClassMethod|Function_|Closure|ArrowFunction $node, NodeFinder $finder): array
    {
        $names = [];

        foreach ($this->bodyNodes($node) as $bodyNode) {
            foreach ($finder->find([$bodyNode], static fn (Node $candidate): bool => $candidate instanceof For_ || $candidate instanceof Foreach_ || $candidate instanceof Catch_) as $scopeNode) {
                if ($scopeNode instanceof For_) {
                    foreach ($finder->findInstanceOf($scopeNode->init, Variable::class) as $variable) {
                        if (is_string($variable->name)) {
                            $names[$variable->name] = true;
                        }
                    }
                }

                if ($scopeNode instanceof Foreach_) {
                    foreach ([$scopeNode->keyVar, $scopeNode->valueVar] as $variable) {
                        if ($variable instanceof Variable && is_string($variable->name)) {
                            $names[$variable->name] = true;
                        }
                    }
                }

                if ($scopeNode instanceof Catch_ && $scopeNode->var instanceof Variable && is_string($scopeNode->var->name)) {
                    $names[$scopeNode->var->name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * @return list<Node>
     */
    private function bodyNodes(ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        if ($node instanceof ArrowFunction) {
            return [$node->expr];
        }

        return array_values($node->stmts ?? []);
    }

    private function symbol(FunctionLikeScope $scope): string
    {
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function lowercaseList(array $values): array
    {
        return array_map(static fn (string $value): string => strtolower($value), $values);
    }
}
