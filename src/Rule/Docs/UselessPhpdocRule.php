<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

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
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

final readonly class UselessPhpdocRule implements RuleInterface
{
    public const ID = 'docs.useless-phpdoc';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Useless PHPDoc',
            pillar: Pillar::Documentation,
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
            $docComment = $node->getDocComment();

            if ($docComment === null) {
                continue;
            }

            $docText = $docComment->getText();
            $stripped = preg_replace('/\/\*\*|\*\/|\*/', '', $docText) ?? $docText;
            $stripped = trim($stripped);

            $lines = array_filter(
                array_map('trim', explode("\n", $stripped)),
                static fn (string $line): bool => $line !== '',
            );

            $hasNonTagContent = false;

            foreach ($lines as $line) {
                if (!str_starts_with($line, '@')) {
                    $hasNonTagContent = true;

                    break;
                }
            }

            if ($hasNonTagContent) {
                continue;
            }

            if ($lines === []) {
                continue;
            }

            $hasOnlyTypeRestatements = true;

            foreach ($lines as $line) {
                if ($this->isBareSignatureRestatement($line)) {
                    continue;
                }

                $hasOnlyTypeRestatements = false;

                break;
            }

            if (!$hasOnlyTypeRestatements) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId: $definition->id,
                message: sprintf('%s has a PHPDoc that only restates the type signature.', $symbol),
                filePath: $unit->file->displayPath,
                line: $node->getStartLine(),
                severity: $definition->defaultSeverity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                symbol: $symbol,
                remediation: 'Add a description or remove the docblock if it adds no information.',
            );
        }

        return $findings;
    }

    private function isBareSignatureRestatement(string $line): bool
    {
        if (preg_match('/^@param\s+(\S+)\s+\$\w+\s*$/', $line, $matches) === 1) {
            return $this->isSimpleDocType($matches[1]);
        }

        if (preg_match('/^@return\s+(\S+)\s*$/', $line, $matches) === 1) {
            return $this->isSimpleDocType($matches[1]);
        }

        return false;
    }

    private function isSimpleDocType(string $type): bool
    {
        if (preg_match('/[<>{}\\[\\]|&]/', $type) === 1) {
            return false;
        }

        return true;
    }
}
