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
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Detects one-character variables outside narrow local conventions.
 */
final readonly class ShortVariableRule implements RuleInterface
{
    /**
     * Stable identifier for the short variable rule.
     */
    public const ID = 'naming.short-variable';

    /**
     * One-character names accepted for local loop counters.
     */
    private const LOOP_COUNTER_ALLOWLIST = ['i', 'j', 'k', 'n', 'x', 'y', 'z'];

    /**
     * One-character names accepted for caught exceptions.
     */
    private const CATCH_ALLOWLIST = ['e'];

    /**
     * Describe the short variable naming rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Short variable name',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find short variable names outside accepted local conventions.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context carrying accepted abbreviations.
     * @return list<Finding> Findings for overly short variable names.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder     = new NodeFinder();

        $loopVars  = $this->collectLoopVars($unit->statements, $finder);
        $catchVars = $this->collectCatchVars($unit->statements, $finder);

        $functions = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod || $node instanceof Function_;
        });

        $findings = [];
        $reported = [];

        foreach ($functions as $fn) {
            /** @var ClassMethod|Function_ $fn Finder predicate restricts results to function-like nodes. */
            $vars   = $finder->findInstanceOf($fn->stmts ?? [], Variable::class);
            $symbol = CyclomaticComplexityRule::resolveSymbol($fn);

            foreach ($vars as $var) {
                /** @var Variable $var NodeFinder narrows the function body walk to variable nodes. */
                if (!is_string($var->name) || strlen($var->name) > 1) {
                    continue;
                }

                $name = $var->name;

                if ($name === '_') {
                    continue;
                }

                if (in_array($name, $context->config->acceptedAbbreviations(), true)) {
                    continue;
                }

                if (in_array($name, self::LOOP_COUNTER_ALLOWLIST, true) && isset($loopVars[$name])) {
                    continue;
                }

                if (in_array($name, self::CATCH_ALLOWLIST, true) && isset($catchVars[$name])) {
                    continue;
                }

                $key = $fn->getStartLine() . ':' . $name;

                if (isset($reported[$key])) {
                    continue;
                }

                $reported[$key] = true;

                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     sprintf('Variable $%s in %s is a single character.', $name, $symbol),
                    filePath:    $unit->file->displayPath,
                    line:        $var->getStartLine(),
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    symbol:      $symbol,
                    remediation: 'Use a descriptive name that communicates the variable\'s purpose.',
                    metadata:    ['variable' => $name],
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<Node> $stmts
     * @return array<string, true>
     */
    private function collectLoopVars(array $stmts, NodeFinder $finder): array
    {
        $vars  = [];
        $loops = $finder->find($stmts, static function (Node $node): bool {
            return $node instanceof For_ || $node instanceof Foreach_;
        });

        foreach ($loops as $loop) {
            if ($loop instanceof For_) {
                foreach ($loop->init as $init) {
                    $initVars = $finder->findInstanceOf([$init], Variable::class);

                    foreach ($initVars as $variable) {
                        if (is_string($variable->name)) {
                            $vars[$variable->name] = true;
                        }
                    }
                }
            }
        }

        return $vars;
    }

    /**
     * @param array<Node> $stmts
     * @return array<string, true>
     */
    private function collectCatchVars(array $stmts, NodeFinder $finder): array
    {
        $vars    = [];
        $catches = $finder->findInstanceOf($stmts, Catch_::class);

        foreach ($catches as $catch) {
            if ($catch->var !== null && is_string($catch->var->name)) {
                $vars[$catch->var->name] = true;
            }
        }

        return $vars;
    }
}
