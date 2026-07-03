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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - this rule's id, pillar/tier, default Advisory severity, and allowed-literal allowlist
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per unexplained literal surviving allowlist and contextual checks; [] if none
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $allowed  = $this->loadAllowedLiterals($ruleContext);
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // User view: add each item that can appear in findings list.
            foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
                $number = TestQualityNodeHelper::isAssertionMagicNumber($call);
                // User view: choose the findings list branch for this case.
                // User view: missing data becomes the expected findings list state.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param RuleContext $ruleContext - Source of the per-rule `allowedLiterals` option for this run.
     *
     * @return list<int> - allowlisted literals treated as self-explanatory; defaults when no valid override is set
     */
    private function loadAllowedLiterals(RuleContext $ruleContext): array
    {
        $configuredLiterals = $ruleContext->settingsFor($this->definition())->option('allowedLiterals');
        // User view: choose the findings list branch for this case.
        if (!is_array($configuredLiterals)) {
            // No (or malformed) override configured, so fall back to the built-in self-explanatory set.
            return self::DEFAULT_ALLOWED_LITERALS;
        }

        $allowedLiterals = [];
        // User view: add each item that can appear in findings list.
        foreach ($configuredLiterals as $configuredLiteral) {
            // User view: choose the findings list branch for this case.
            if (is_int($configuredLiteral)) {
                $allowedLiterals[] = $configuredLiteral;
            }
        }

        return $allowedLiterals;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Assertion call whose numeric literal is judged.
     *
     * @return bool - true when the call is a cardinality assertion or its compared value labels the number's meaning
     */
    private function hasContextualNumericTarget(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = TestQualityNodeHelper::callName($call);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($name === null) {
            // A dynamic call target has no resolvable name, so it cannot be a known contextual assertion.
            return false;
        }

        // User view: choose the findings list branch for this case.
        if (in_array($name, self::CARDINALITY_ASSERTIONS, true)) {
            // A cardinality assertion's literal is the expected count itself, so it needs no separate name.
            return true;
        }

        $actual = $this->actualAssertionExpression($call, $name);

        // Contextual only when the compared-against expression labels what the number means.
        // User view: missing data becomes the expected findings list state.
        return $actual !== null && $this->isContextualNumericExpression($actual);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Assertion or Pest expectation call to read from.
     * @param string                                        $name - Lowercased call name that selects the extraction path.
     *
     * @return Expr|null - the actual value compared against the literal; null when no such expression is present
     */
    private function actualAssertionExpression(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, string $name): ?Expr
    {
        // User view: choose the findings list branch for this case.
        if ($call instanceof Expr\MethodCall && in_array($name, ['tobe', 'toequal', 'tohavecount'], true)) {
            // Pest puts the value under test on expect(value), not in the matcher's arguments.
            return TestQualityNodeHelper::pestExpectationValue($call);
        }

        // PHPUnit assertions place the actual value at argument index 1 (after the expected literal).
        return TestQualityNodeHelper::argValue($call, 1);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr $expr - Expression compared against the numeric literal; wrappers are unwrapped recursively.
     *
     * @return bool - true when the expression (count call, named getter, or contextual property/key) labels the number
     */
    private function isContextualNumericExpression(Expr $expr): bool
    {
        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            // A `value ?? fallback` is contextual when its primary (left) side names the number.
            return $this->isContextualNumericExpression($expr->left);
        }

        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\FuncCall) {
            // A bare count(...) already declares the literal is a cardinality.
            return TestQualityNodeHelper::functionName($expr) === 'count';
        }

        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\StaticCall) {
            $name = TestQualityNodeHelper::callName($expr);

            // A getter such as coverageRate() or getExitCode() names what the number means.
            // User view: missing data becomes the expected findings list state.
            return $name !== null && in_array($name, self::CONTEXTUAL_METHOD_NAMES, true);
        }

        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\PropertyFetch) {
            // Contextual when the property name names the number, or the receiver chain does.
            return $this->isContextualName($expr->name)
                   || $this->isContextualNumericExpression($expr->var);
        }

        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\ArrayDimFetch) {
            // Contextual when the array key names the number, or the receiver chain does.
            return $this->isContextualArrayKey($expr->dim)
                   || $this->isContextualNumericExpression($expr->var);
        }

        // Any other expression shape gives the literal no name, so it stays a magic number.
        return false;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Property-name node to inspect; only a literal identifier carries a comparable name.
     *
     * @return bool - true when the property is a static identifier with a contextual name; false for dynamic names
     */
    private function isContextualName(Node $node): bool
    {
        // User view: choose the findings list branch for this case.
        if (!$node instanceof Node\Identifier) {
            // A dynamic property such as $obj->$prop has no static name to match against the allowlist.
            return false;
        }

        return in_array($this->normalizeName($node->toString()), self::CONTEXTUAL_NUMERIC_NAMES, true);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr|null $expr - Array-dimension node; only a literal string key carries a comparable name.
     *
     * @return bool - true when the key is a literal string with a contextual normalized value; false for computed keys
     */
    private function isContextualArrayKey(?Expr $expr): bool
    {
        // User view: choose the findings list branch for this case.
        if (!$expr instanceof Scalar\String_) {
            // A computed, variable, or absent key has no static string to match against the allowlist.
            return false;
        }

        return in_array($this->normalizeName($expr->value), self::CONTEXTUAL_NUMERIC_NAMES, true);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $name - Raw identifier or array key whose case and separators are insignificant for matching.
     *
     * @return string - the name lowercased with non-alphanumeric characters stripped, for case-insensitive lookup
     */
    private function normalizeName(string $name): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', '', $name);

        return strtolower((string)$normalized);
    }
}
