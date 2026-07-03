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
 * Detects tests that assert exception type without checking its details.
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
     * Describe the exception type-only assertion rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        );
    }

    /**
     * Find tests that assert only an exception type without message or state.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for type-only exception tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $typeOnlyCall     = null;
            $hasSupplementary = false;

            // User view: add each item that can appear in findings list.
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                // User view: choose the findings list branch for this case.
                if (!$call instanceof Expr\MethodCall && !$call instanceof Expr\StaticCall) {
                    continue;
                }

                $name = TestQualityNodeHelper::callName($call);
                // User view: choose the findings list branch for this case.
                // User view: missing data becomes the expected findings list state.
                if ($name === null) {
                    continue;
                }

                // User view: choose the findings list branch for this case.
                // User view: missing data becomes the expected findings list state.
                if (in_array($name, self::TYPE_ONLY_METHODS, true) && $typeOnlyCall === null) {
                    $typeOnlyCall = $call;
                    continue;
                }

                // User view: choose the findings list branch for this case.
                if (in_array($name, self::SUPPLEMENTARY_METHODS, true)) {
                    $hasSupplementary = true;
                }
            }

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
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
