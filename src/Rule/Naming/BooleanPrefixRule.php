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
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

final readonly class BooleanPrefixRule implements RuleInterface
{
    public const ID = 'naming.boolean-prefix';

    private const GOOD_PREFIXES = ['is', 'has', 'can', 'should', 'will', 'was', 'does', 'allows'];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Boolean method prefix',
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
        $nodes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node */
            if (!$this->returnsBool($node)) {
                continue;
            }

            $name = $node->name->toString();

            foreach (self::GOOD_PREFIXES as $prefix) {
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }

                if (strlen($name) === strlen($prefix)) {
                    continue 2;
                }

                $nextChar = $name[strlen($prefix)];
                if ($nextChar >= 'A' && $nextChar <= 'Z') {
                    continue 2;
                }
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf('%s returns bool but does not use a boolean prefix (is, has, can, should, will).', $symbol),
                filePath: $unit->file->displayPath,
                line: $node->getStartLine(),
                severity: $definition->defaultSeverity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                symbol: $symbol,
                remediation: 'Rename to use a boolean prefix, e.g. isActive(), hasPermission().',
            );
        }

        return $findings;
    }

    private function returnsBool(ClassMethod|Function_ $node): bool
    {
        $returnType = $node->getReturnType();

        if ($returnType instanceof Identifier) {
            return $returnType->toString() === 'bool';
        }

        if ($returnType instanceof Name) {
            return $returnType->toString() === 'bool';
        }

        return false;
    }
}
