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

/**
 * Detects PHPDoc blocks that add tags without useful descriptive context.
 */
final readonly class UselessPhpdocRule implements RuleInterface
{
    /**
     * Stable rule identifier for useless PHPDoc findings.
     */
    public const ID = 'docs.useless-phpdoc';

    /**
     * Describe the useless PHPDoc rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
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

    /**
     * Find docblocks that only restate native parameter or return types.
     *
     * @return list<Finding> Findings for redundant PHPDoc blocks.
     */
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
            if ($this->containsLoadBearingTypeDoc($docText)) {
                continue;
            }

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

    /**
     * Check whether one PHPDoc tag only repeats a native signature type.
     *
     * @return bool True when the tag is a bare type restatement.
     */
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

    /**
     * Decide whether a PHPDoc type can be represented directly in a PHP signature.
     *
     * @return bool True when the type is simple enough to be redundant.
     */
    private function isSimpleDocType(string $type): bool
    {
        if (preg_match('/[<>{}\\[\\]|&]/', $type) === 1) {
            return false;
        }

        // `resource` is not a valid PHP signature type, so a `@param resource` docblock is the only
        // place this type can live - it is never a redundant restatement of the signature.
        if (strtolower($type) === 'resource') {
            return false;
        }

        return true;
    }

    /**
     * Detect PHPDoc type syntax that carries information unavailable in native types.
     *
     * @return bool True when the docblock contains load-bearing type details.
     */
    private function containsLoadBearingTypeDoc(string $docText): bool
    {
        foreach (['array{', 'list<', 'non-empty-array', 'Collection<', 'class-string', '@phpstan-'] as $marker) {
            if (str_contains($docText, $marker)) {
                return true;
            }
        }

        return false;
    }
}
