<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;

/**
 * Detects assertions that compare against unexplained numeric literals.
 */
final readonly class MagicNumberAssertionRule implements RuleInterface
{
    /**
     * Stable identifier for the magic number assertion rule.
     */
    public const ID = 'test-quality.magic-number-assertion';

    /**
     * Numeric literals considered self-explanatory in common assertions.
     */
    private const DEFAULT_ALLOWED_LITERALS = [
        // HTTP status codes (commonly asserted in CLI/API tests, well-understood by readers).
        200, 201, 202, 204, 301, 302, 303, 304, 307, 308,
        400, 401, 403, 404, 405, 409, 410, 422, 429,
        500, 502, 503, 504,
    ];

    /**
     * Describe the magic number assertion rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Magic number assertion',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Low,
            defaultOptions:  ['allowedLiterals' => self::DEFAULT_ALLOWED_LITERALS],
        );
    }

    /**
     * Find assertions that compare against unexplained numeric literals.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for magic numbers in assertions.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $allowed  = $this->loadAllowedLiterals($context);
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
                $number = TestQualityNodeHelper::isAssertionMagicNumber($call);
                if ($number === null || in_array($number, $allowed, true)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s asserts the unexplained literal %d.', $scope->symbol, $number),
                    filePath:    $unit->file->displayPath,
                    line:        $call->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Low,
                    symbol:      $scope->symbol,
                    remediation: 'Name important constants or derive expected values from arranged data when that improves readability.',
                    metadata:    ['number' => $number],
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<int>
     */
    private function loadAllowedLiterals(RuleContext $context): array
    {
        $raw = $context->settingsFor($this->definition())->option('allowedLiterals');
        if (!is_array($raw)) {
            return self::DEFAULT_ALLOWED_LITERALS;
        }

        $values = [];
        foreach ($raw as $value) {
            if (is_int($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
