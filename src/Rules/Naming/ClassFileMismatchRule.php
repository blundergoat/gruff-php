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
 * Reports a file whose one class, interface, trait or enum is named differently from the file, which breaks PSR-4 autoloading.
 *
 * A user who renamed `UserRepository` to `AccountRepository` but kept `UserRepository.php` gets one warning on the declaration.
 * Scripts, hyphenated entry points and files declaring several types stay quiet.
 *
 * The advice always offers the file rename, and offers the class rename only when the file name is already a StudlyCaps
 * class name, so `widgets.php` never suggests a class called `widgets`.
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
        // Warning rather than advisory: a name that disagrees with PSR-4 autoloading is a concrete defect, not a smell.
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
     * Reports the file's lone type declaration when its name disagrees with the file name.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
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

        /** @var Class_|Interface_|Trait_|Enum_ $declaration Finder predicate restricts results to named class-like declarations. */
        $declaration = $declarations[0];
        $typeName    = $declaration->name?->toString();

        // An unnamed declaration has nothing to compare with the file name.
        if ($typeName === null) {
            return [];
        }

        $filePath = $analysisUnit->file->displayPath;
        $fileStem = pathinfo($filePath, PATHINFO_FILENAME);

        // A file name that could never be a class name, such as `run-migrations.php`, is a script, not a mismatch.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fileStem) !== 1) {
            return [];
        }

        // Matching names are the expected case, so the user sees nothing.
        if ($fileStem === $typeName) {
            return [];
        }

        // A StudlyCaps file name such as `Widget.php` is a usable class name; a lowercase one such as `widgets.php` is not.
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
