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
 * Flags a test that calls `expectException()` on a type but never pairs it with a message, code, or object
 * check - so a different exception of the same class would still pass, and the test barely pins the failure
 * down. Runs over every test in the file. Advisory, medium confidence - a bare type check is sometimes fine.
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
     * Reports tests that assert only an exception type without message or state.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for type-only exception tests.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $typeOnlyCall     = null;
            $hasSupplementary = false;

            // Scan the test's calls for exception expectations.
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                // Only method and static calls can be an expectException() family call.
                if (!$call instanceof Expr\MethodCall && !$call instanceof Expr\StaticCall) {
                    continue;
                }

                $name = TestQualityNodeHelper::callName($call);
                // A call with no resolvable name cannot be classified.
                if ($name === null) {
                    continue;
                }

                // Remember the first bare type expectation the test makes.
                if (in_array($name, self::TYPE_ONLY_METHODS, true) && $typeOnlyCall === null) {
                    $typeOnlyCall = $call;
                    continue;
                }

                // A message/code/object check makes the expectation specific enough.
                if (in_array($name, self::SUPPLEMENTARY_METHODS, true)) {
                    $hasSupplementary = true;
                }
            }

            // Flag only a lone type expectation with nothing to narrow it.
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
