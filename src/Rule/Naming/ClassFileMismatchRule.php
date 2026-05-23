<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Detects single-type files whose class-like name does not match the file name.
 */
final readonly class ClassFileMismatchRule implements RuleInterface
{
    /**
     * Stable rule identifier for class/file mismatch findings.
     */
    public const ID = 'naming.class-file-mismatch';

    /**
     * Describe the class-file mismatch naming rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Class/file name mismatch',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find primary class names that do not match their file names.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for class and file name mismatches.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $classLikes = $nodeFinder->find($analysisUnit->statements, static function (Node $node): bool {
            return ($node instanceof Class_ && !$node->isAnonymous())
                || $node instanceof Interface_
                || $node instanceof Trait_
                || $node instanceof Enum_;
        });

        if (count($classLikes) !== 1) {
            return [];
        }

        /** @var Class_|Interface_|Trait_|Enum_ $classLike Finder predicate restricts results to named class-like declarations. */
        $classLike = $classLikes[0];
        $className = $classLike->name?->toString();

        if ($className === null) {
            return [];
        }

        $filePath = $analysisUnit->file->displayPath;
        $fileName = pathinfo($filePath, PATHINFO_FILENAME);

        // Compare only filenames that are valid PHP class identifiers.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fileName) !== 1) {
            return [];
        }

        if ($fileName === $className) {
            return [];
        }

        return [
            new Finding(
                ruleId:      $definition->id,
                message:     sprintf('Class %s does not match file name %s.php.', $className, $fileName),
                filePath:    $filePath,
                line:        $classLike->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $className,
                remediation: sprintf('Rename the file to %s.php or the class to %s.', $className, $fileName),
            ),
        ];
    }
}
