<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
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
use PhpParser\NodeFinder;

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
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for undocumented constants.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder     = new NodeFinder();
        $findings   = [];

        foreach ($finder->findInstanceOf($unit->statements, ClassLike::class) as $classLike) {
            if (!$classLike instanceof Class_
                && !$classLike instanceof Trait_
                && !$classLike instanceof Interface_
                && !$classLike instanceof Enum_) {
                continue;
            }

            if ($classLike->name === null) {
                continue;
            }

            $className = $classLike->name->toString();

            foreach ($classLike->stmts as $statement) {
                if ($statement instanceof ClassConst) {
                    if ($statement->getDocComment() !== null) {
                        continue;
                    }

                    foreach ($statement->consts as $const) {
                        $constantName = $const->name->toString();
                        $symbol       = sprintf('%s::%s', $className, $constantName);

                        $findings[] = new Finding(
                            ruleId:      $definition->id,
                            message:     sprintf('Constant %s has no PHPDoc.', $symbol),
                            filePath:    $unit->file->displayPath,
                            line:        $statement->getStartLine(),
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
                }
            }

            if (!$classLike instanceof Enum_) {
                continue;
            }

            if ($classLike->getDocComment() !== null) {
                continue;
            }

            foreach ($classLike->stmts as $statement) {
                if (!$statement instanceof EnumCase) {
                    continue;
                }

                if ($statement->getDocComment() !== null) {
                    continue;
                }

                $caseName = $statement->name->toString();
                $symbol   = sprintf('%s::%s', $className, $caseName);

                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     sprintf('Enum case %s has no PHPDoc and the enum itself is undocumented.', $symbol),
                    filePath:    $unit->file->displayPath,
                    line:        $statement->getStartLine(),
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

        return $findings;
    }
}
