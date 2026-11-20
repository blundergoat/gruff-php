<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Reports a lone named class, interface, trait, or enum whose name differs from the file stem.
 * Matching the type and file names supports PSR-4 autoloading.
 *
 * Files with no named type, multiple named types, or a stem outside the rule's identifier pattern are skipped.
 * A file rename is always offered; a type rename is offered only for an uppercase-first alphanumeric stem.
 */
final readonly class ClassFileMismatchRule implements RuleInterface
{
    /**
     * Stable rule identifier for class/file mismatch findings.
     */
    public const ID = 'naming.class-file-mismatch';

    /**
     * Describes the rule for `list-rules` and for each finding it reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning marks a direct class/file naming mismatch rather than a general naming smell.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Class/file name mismatch',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape'      => 'A legacy class name can join directory names and the file stem with `_`, and the rule compares that entire declared name with the stem.',
                    'mitigation' => 'Move the directory segments into a namespace, name the class after the file stem, and preserve the legacy name with `class_alias()` if callers still use it.',
                ],
                [
                    'shape'      => 'A script, fixture, or type-assertion file can be reported when its stem matches `/^[A-Za-z_][A-Za-z0-9_]*$/` and its sole named class-like declaration has a different name.',
                    'mitigation' => 'If the supporting type is shared, move it to a file whose stem matches its declared name and load it from the original role-based file.',
                ],
            ],
        );
    }

    /**
     * Reports the file's lone type declaration when its name disagrees with the file name.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding for a mismatched declaration; empty when the names agree or the file is out of scope.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition   = $this->definition();
        $declarations = (new NodeFinder())->find($analysisUnit->statements, static function (Node $node): bool {
            // An anonymous class has no name to compare with the file, so it never counts as the file's declaration.
            return ($node instanceof Class_ && !$node->isAnonymous())
                || $node instanceof Interface_
                || $node instanceof Trait_
                || $node instanceof Enum_;
        });

        // One file, one type is the convention; a file with no type or several types has no single name to match.
        if (count($declarations) !== 1) {
            return [];
        }

        /** @var Class_|Interface_|Trait_|Enum_ $declaration - Finder predicate restricts results to named class-like declarations. */
        $declaration = $declarations[0];
        $typeName    = $declaration->name?->toString();


        if ($typeName === null) {
            return [];
        }

        $filePath = $analysisUnit->file->displayPath;
        $fileStem = pathinfo($filePath, PATHINFO_FILENAME);

        // File stems outside the rule's ASCII identifier shape are excluded from class/file comparison.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fileStem) !== 1) {
            return [];
        }


        if ($fileStem === $typeName) {
            return [];
        }

        // Offer the file stem as a class name only when it starts with an uppercase ASCII letter and the rest is ASCII alphanumeric.
        $remediation = preg_match('/^[A-Z][A-Za-z0-9]*$/', $fileStem) === 1
            ? sprintf('Rename the file to %s.php or the class to %s.', $typeName, $fileStem)
            : sprintf('Rename the file to %s.php so it matches the class it declares.', $typeName);

        return [
            new Finding(
                ruleId:      $definition->id,
                message:     sprintf('Class %s does not match file name %s.php.', $typeName, $fileStem),
                filePath:    $filePath,
                line:        $declaration->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $typeName,
                remediation: $remediation,
            ),
        ];
    }
}
