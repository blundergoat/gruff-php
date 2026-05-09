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
use PhpParser\NodeFinder;

final readonly class HungarianNotationRule implements RuleInterface
{
    public const ID = 'naming.hungarian-notation';

    private const PREFIXES = ['str', 'int', 'float', 'bool', 'arr', 'obj', 'fn', 'cls'];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Hungarian notation',
            pillar: Pillar::Naming,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $functions = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod || $node instanceof Function_;
        });

        $findings = [];
        $reported = [];

        foreach ($functions as $fn) {
            /** @var ClassMethod|Function_ $fn */
            $vars = $finder->findInstanceOf($fn->stmts ?? [], Variable::class);
            $symbol = CyclomaticComplexityRule::resolveSymbol($fn);

            foreach ($vars as $var) {
                /** @var Variable $var */
                if (!is_string($var->name)) {
                    continue;
                }

                $name = $var->name;
                $prefix = $this->detectPrefix($name);

                if ($prefix === null) {
                    continue;
                }

                $key = $fn->getStartLine() . ':' . $name;

                if (isset($reported[$key])) {
                    continue;
                }

                $reported[$key] = true;

                $findings[] = new Finding(
                    ruleId: $definition->id,
                    message: sprintf('Variable $%s in %s uses Hungarian notation prefix "%s".', $name, $symbol, $prefix),
                    filePath: $unit->file->displayPath,
                    line: $var->getStartLine(),
                    severity: $definition->defaultSeverity,
                    pillar: $definition->pillar,
                    tier: $definition->tier,
                    confidence: $definition->confidence,
                    symbol: $symbol,
                    remediation: sprintf('Remove the type prefix. Use $%s instead.', lcfirst(substr($name, strlen($prefix)))),
                    metadata: ['variable' => $name, 'prefix' => $prefix],
                );
            }
        }

        return $findings;
    }

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
}
