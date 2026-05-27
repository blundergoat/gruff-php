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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

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
     * Assertions whose numeric literal is explicitly a cardinality.
     *
     * @var list<string>
     */
    private const CARDINALITY_ASSERTIONS = [
        'assertcount',
        'tohavecount',
    ];

    /**
     * Methods whose names already explain the expected numeric contract.
     *
     * @var list<string>
     */
    private const CONTEXTUAL_METHOD_NAMES = [
        'computecognitivecomplexity',
        'coveragerate',
        'coveredmsi',
        'getexitcode',
        'msi',
        'numericthreshold',
        'survivedmutants',
        'totalmutants',
    ];

    /**
     * Array keys and properties whose names already explain the expected numeric contract.
     *
     * @var list<string>
     */
    private const CONTEXTUAL_NUMERIC_NAMES = [
        'advisory',
        'complexity',
        'coveredmsi',
        'coveragerate',
        'currentscore',
        'delta',
        'error',
        'exitcode',
        'filesdiscovered',
        'findings',
        'line',
        'lines',
        'averagelength',
        'count',
        'methodcount',
        'msi',
        'npath',
        'parameters',
        'parseerrors',
        'previousscore',
        'properties',
        'publicmethods',
        'score',
        'survivedmutants',
        'threshold',
        'total',
        'totalmutants',
        'warning',
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for magic numbers in assertions.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $allowed  = $this->loadAllowedLiterals($ruleContext);
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
                $number = TestQualityNodeHelper::isAssertionMagicNumber($call);
                if ($number === null || in_array($number, $allowed, true) || $this->hasContextualNumericTarget($call)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s asserts the unexplained literal %d.', $scope->symbol, $number),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $call->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Low,
                    symbol:      $scope->symbol,
                    remediation: 'Name important constants or derive expected values from arranged data when that improves readability. If the literal is a stable domain code (HTTP status, well-known port, protocol constant), add it to `rules.test-quality.magic-number-assertion.options.allowedLiterals` in `.gruff-php.yaml`.',
                    metadata:    ['number' => $number],
                );
            }
        }

        return $findings;
    }

    /**
     * Load configured assertion literals, falling back to the default self-explanatory values.
     *
     * @return list<int>
     */
    private function loadAllowedLiterals(RuleContext $ruleContext): array
    {
        $configuredLiterals = $ruleContext->settingsFor($this->definition())->option('allowedLiterals');
        if (!is_array($configuredLiterals)) {
            return self::DEFAULT_ALLOWED_LITERALS;
        }

        $allowedLiterals = [];
        foreach ($configuredLiterals as $configuredLiteral) {
            if (is_int($configuredLiteral)) {
                $allowedLiterals[] = $configuredLiteral;
            }
        }

        return $allowedLiterals;
    }

    /**
     * @return bool True when the assertion target already names the number's meaning.
     */
    private function hasContextualNumericTarget(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = TestQualityNodeHelper::callName($call);
        if ($name === null) {
            return false;
        }

        if (in_array($name, self::CARDINALITY_ASSERTIONS, true)) {
            return true;
        }

        $actual = $this->actualAssertionExpression($call, $name);

        return $actual !== null && $this->isContextualNumericExpression($actual);
    }

    /**
     * @return Expr|null The assertion expression being checked against the numeric literal.
     */
    private function actualAssertionExpression(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, string $name): ?Expr
    {
        if ($call instanceof Expr\MethodCall && in_array($name, ['tobe', 'toequal', 'tohavecount'], true)) {
            return TestQualityNodeHelper::pestExpectationValue($call);
        }

        return TestQualityNodeHelper::argValue($call, 1);
    }

    /**
     * @return bool True when the expression labels the expected numeric value.
     */
    private function isContextualNumericExpression(Expr $expr): bool
    {
        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            return $this->isContextualNumericExpression($expr->left);
        }

        if ($expr instanceof Expr\FuncCall) {
            return TestQualityNodeHelper::functionName($expr) === 'count';
        }

        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\StaticCall) {
            $name = TestQualityNodeHelper::callName($expr);

            return $name !== null && in_array($name, self::CONTEXTUAL_METHOD_NAMES, true);
        }

        if ($expr instanceof Expr\PropertyFetch) {
            return $this->isContextualName($expr->name)
                || $this->isContextualNumericExpression($expr->var);
        }

        if ($expr instanceof Expr\ArrayDimFetch) {
            return $this->isContextualArrayKey($expr->dim)
                || $this->isContextualNumericExpression($expr->var);
        }

        return false;
    }

    /**
     * @return bool True when the property node carries a contextual numeric name.
     */
    private function isContextualName(Node $node): bool
    {
        if (!$node instanceof Node\Identifier) {
            return false;
        }

        return in_array($this->normalizeName($node->toString()), self::CONTEXTUAL_NUMERIC_NAMES, true);
    }

    /**
     * @return bool True when the array key carries a contextual numeric name.
     */
    private function isContextualArrayKey(?Expr $expr): bool
    {
        if (!$expr instanceof Scalar\String_) {
            return false;
        }

        return in_array($this->normalizeName($expr->value), self::CONTEXTUAL_NUMERIC_NAMES, true);
    }

    /**
     * @return string Normalized identifier for loose config/report key matching.
     */
    private function normalizeName(string $name): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', '', $name);

        return strtolower((string) $normalized);
    }
}
