<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Detects class constants and enum cases that lack local PHPDoc.
 */
final readonly class MissingConstantPhpdocRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing constant PHPDoc findings.
     */
    public const ID = 'docs.missing-constant-phpdoc';

    /**
     * Describe the missing constant PHPDoc rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing constant PHPDoc',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find class constants and enum cases without PHPDoc.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for undocumented constants.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, ClassLike::class) as $classLike) {
            if (!$this->isSupportedClassLike($classLike) || $classLike->name === null) {
                continue;
            }

            array_push(
                $findings,
                ...$this->classConstantFindings($classLike, $classLike->name->toString(), $definition, $analysisUnit),
                ...$this->enumCaseFindings($classLike, $classLike->name->toString(), $definition, $analysisUnit),
            );
        }

        return $findings;
    }

    /**
     * Check whether a class-like node can own constants or enum cases.
     *
     * @return bool True when the node should be inspected.
     */
    private function isSupportedClassLike(ClassLike $classLike): bool
    {
        return $classLike instanceof Class_
            || $classLike instanceof Trait_
            || $classLike instanceof Interface_
            || $classLike instanceof Enum_;
    }

    /**
     * Find undocumented class constants in one class-like node.
     *
     * @return list<Finding> Findings for undocumented class constants.
     */
    private function classConstantFindings(
        ClassLike $classLike,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): array {
        $findings = [];

        foreach ($classLike->stmts as $statement) {
            if (!$statement instanceof ClassConst || $statement->getDocComment() !== null) {
                continue;
            }

            foreach ($statement->consts as $const) {
                $findings[] = $this->classConstantFinding(
                    constantName: $const->name->toString(),
                    className:    $className,
                    line:         $statement->getStartLine(),
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                );
            }
        }

        return $findings;
    }

    /**
     * Build one class-constant PHPDoc finding.
     *
     * @return Finding Finding for an undocumented class constant.
     */
    private function classConstantFinding(
        string $constantName,
        string $className,
        int $line,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): Finding {
        $symbol = sprintf('%s::%s', $className, $constantName);

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('Constant %s has no PHPDoc.', $symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Add a one-line docblock above the constant.',
            metadata:    [
                'constantName' => $constantName,
                'kind' => 'class-constant',
                'className' => $className,
            ],
        );
    }

    /**
     * Find undocumented enum cases when the enum itself is undocumented.
     *
     * @return list<Finding> Findings for undocumented enum cases.
     */
    private function enumCaseFindings(
        ClassLike $classLike,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): array {
        if (!$classLike instanceof Enum_ || $classLike->getDocComment() !== null) {
            return [];
        }

        $findings = [];
        foreach ($classLike->stmts as $statement) {
            if (!$statement instanceof EnumCase || $statement->getDocComment() !== null) {
                continue;
            }

            $findings[] = $this->enumCaseFinding(
                caseName:     $statement->name->toString(),
                className:    $className,
                line:         $statement->getStartLine(),
                definition:   $definition,
                analysisUnit: $analysisUnit,
            );
        }

        return $findings;
    }

    /**
     * Build one enum-case PHPDoc finding.
     *
     * @return Finding Finding for an undocumented enum case.
     */
    private function enumCaseFinding(
        string $caseName,
        string $className,
        int $line,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): Finding {
        $symbol = sprintf('%s::%s', $className, $caseName);

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('Enum case %s has no PHPDoc and the enum itself is undocumented.', $symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Document either each case or add a class-level docblock to the enum.',
            metadata:    [
                'constantName' => $caseName,
                'kind' => 'enum-case',
                'className' => $className,
            ],
        );
    }
}
