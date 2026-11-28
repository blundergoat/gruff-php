<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Expr;

/**
 * Reports test scopes containing type-only exception expectations without recognized supplementary calls.
 * A message, code, or object expectation anywhere in the scanned scope suppresses the finding.
 * Findings are advisory with medium confidence because checking only the type can be intentional.
 */
final readonly class ExceptionTypeOnlyRule implements RuleInterface
{
    /**
     * Stable identifier for the exception type-only rule.
     */
    public const ID = 'test-quality.exception-type-only';

    /**
     * Expectation methods that assert only the exception type.
     */
    private const TYPE_ONLY_METHODS = ['expectexception', 'expectexceptiontype'];

    /**
     * Expectation methods that add message, code, or object checks.
     */
    private const SUPPLEMENTARY_METHODS = [
        'expectexceptionmessage',
        'expectexceptionmessagematches',
        'expectexceptioncode',
        'expectexceptionobject',
    ];

    /**
     * Describes the exception-type-only rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: a bare expectException is sometimes intentional, so this stays advisory.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Exception type-only assertion',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape'      => 'A test for an exception type that carries no message or code of its own, where the type is the entire contract.',
                    'mitigation' => 'A bare type check is reported without inspecting the exception class, so accept the advisory when the type alone is what the caller distinguishes.',
                ],
            ],
        );
    }

    /**
     * Reports test scopes with recognized type-expectation call names and no recognized supplementary call names.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - Findings for scopes containing a recognized type-only call without a recognized supplementary call.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $typeOnlyCall     = null;
            $hasSupplementary = false;


            foreach (TestQualityNodeHelper::calls($scope) as $call) {

                if (!$call instanceof Expr\MethodCall && !$call instanceof Expr\StaticCall) {
                    continue;
                }

                $name = TestQualityNodeHelper::callName($call);

                if ($name === null) {
                    continue;
                }


                if (in_array($name, self::TYPE_ONLY_METHODS, true) && $typeOnlyCall === null) {
                    $typeOnlyCall = $call;
                    continue;
                }


                if (in_array($name, self::SUPPLEMENTARY_METHODS, true)) {
                    $hasSupplementary = true;
                }
            }


            if ($typeOnlyCall === null || $hasSupplementary) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:  self::ID,
                message: sprintf(
                    '%s expects an exception type but does not assert its message, code, or object.',
                    $scope->symbol,
                ),
                filePath:    $analysisUnit->file->displayPath,
                line:        $typeOnlyCall->getStartLine(),
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                symbol:      $scope->symbol,
                remediation: 'Pair expectException() with expectExceptionMessage(), expectExceptionMessageMatches(), expectExceptionCode(), or expectExceptionObject() so a different exception with the same type still fails the test.',
            );
        }

        return $findings;
    }
}
