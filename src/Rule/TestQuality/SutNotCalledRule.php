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
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * Detects test names that imply a subject call absent from the body.
 */
final readonly class SutNotCalledRule implements RuleInterface
{
    /**
     * Stable identifier for the SUT-not-called rule.
     */
    public const ID = 'test-quality.sut-not-called';

    /**
     * Subprocess APIs that can hide subject execution from static call matching.
     */
    private const SUBPROCESS_FUNCTIONS = ['shell_exec', 'proc_open', 'popen', 'passthru', 'system', 'exec'];

    /**
     * CamelCase tokens that separate a leading method-style phrase from expected outcome text.
     *
     * @var list<string>
     */
    private const OUTCOME_MARKERS = [
        'And',
        'Builds',
        'Calls',
        'Can',
        'Creates',
        'Handles',
        'Processes',
        'Renders',
        'Returns',
        'Sends',
        'Should',
        'Throws',
        'When',
        'With',
    ];

    /**
     * Verbs that make the leading test-name phrase look like an actual method name.
     *
     * @var list<string>
     */
    private const METHOD_VERBS = [
        'analyse',
        'analyze',
        'build',
        'calculate',
        'call',
        'create',
        'decode',
        'detect',
        'discover',
        'encode',
        'escape',
        'find',
        'format',
        'handle',
        'load',
        'parse',
        'process',
        'read',
        'record',
        'render',
        'resolve',
        'send',
        'write',
    ];

    /**
     * Common third-person verb forms used in PHPUnit names.
     *
     * @var array<string, string>
     */
    private const VERB_ALIASES = [
        'analyses' => 'analyse',
        'analyzes' => 'analyze',
        'builds' => 'build',
        'calculates' => 'calculate',
        'calls' => 'call',
        'creates' => 'create',
        'decodes' => 'decode',
        'detects' => 'detect',
        'discovers' => 'discover',
        'encodes' => 'encode',
        'escapes' => 'escape',
        'finds' => 'find',
        'formats' => 'format',
        'handles' => 'handle',
        'loads' => 'load',
        'parses' => 'parse',
        'processes' => 'process',
        'reads' => 'read',
        'records' => 'record',
        'renders' => 'render',
        'resolves' => 'resolve',
        'sends' => 'send',
        'writes' => 'write',
    ];

    /**
     * Describe the SUT-not-called test rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Test name mentions SUT that is not called',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Low,
        );
    }

    /**
     * Find tests whose name implies a SUT call that is absent from the body.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for mismatched test names and calls.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $candidates = $this->candidateSutNames($scope->name);
            if ($scope->isPest || $candidates === [] || TestQualityNodeHelper::assertionCalls($scope) === []) {
                continue;
            }

            if ($this->invokesSubprocess($scope)) {
                continue;
            }

            if ($this->hasNamedSutCall($scope, $candidates)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s name implies a SUT behavior, but no matching method call was detected.', $scope->symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->line,
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::Low,
                symbol:      $scope->symbol,
                remediation: 'Check whether the test name still matches the behavior under test; this heuristic ignores custom dispatch and helpers.',
                metadata:    ['candidates' => $candidates],
            );
        }

        return $findings;
    }

    /**
     * @param list<string> $candidates
     *
     * @return bool True when a non-assertion call matches a candidate SUT name.
     */
    private function hasNamedSutCall(TestQualityScope $scope, array $candidates): bool
    {
        $candidateLookup = array_fill_keys($candidates, true);

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if (TestQualityNodeHelper::isAssertionCall($call) || TestQualityNodeHelper::isMockCreationCall($call) || TestQualityNodeHelper::isMockVerificationCall($call)) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            if ($name !== null && isset($candidateLookup[TestQualityNodeHelper::normalizedTestName($name)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect subprocess-based tests that may invoke the SUT outside the AST call graph.
     *
     * @return bool True when the test launches a subprocess.
     */
    private function invokesSubprocess(TestQualityScope $scope): bool
    {
        $nodeFinder = new NodeFinder();

        $hasProcessNew = $nodeFinder->find(
            $scope->statements,
            static function (Node $node): bool {
                if (!$node instanceof Expr\New_ || !$node->class instanceof Name) {
                    return false;
                }

                $short = strtolower($node->class->getLast());

                return $short === 'process' || $short === 'phpprocess';
            },
        ) !== [];

        if ($hasProcessNew) {
            return true;
        }

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if ($call instanceof Expr\FuncCall) {
                $name = TestQualityNodeHelper::functionName($call);
                if ($name !== null && in_array($name, self::SUBPROCESS_FUNCTIONS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function candidateSutNames(string $testName): array
    {
        if (!str_starts_with($testName, 'test') || str_contains($testName, '_')) {
            return [];
        }

        $afterTest = substr($testName, 4);
        if ($afterTest === '') {
            return [];
        }

        $tokens = $this->camelCaseTokens($afterTest);
        if ($tokens === []) {
            return [];
        }

        $markerIndex = $this->firstOutcomeMarkerIndex($tokens);
        if ($markerIndex === null || $markerIndex === 0) {
            return [];
        }

        $methodTokens = array_slice($tokens, 0, $markerIndex);
        $verb         = $this->methodVerb($methodTokens[0] ?? '');
        if ($verb === null) {
            return [];
        }

        $candidates = [TestQualityNodeHelper::normalizedTestName(implode('', $methodTokens))];

        if (count($methodTokens) > 1) {
            $candidates[] = TestQualityNodeHelper::normalizedTestName($verb);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return list<string>
     */
    private function camelCaseTokens(string $identifierName): array
    {
        if (preg_match_all('/[A-Z]+(?=[A-Z][a-z]|\d|$)|[A-Z]?[a-z]+|\d+/', $identifierName, $matches) < 1) {
            return [];
        }

        return $matches[0];
    }

    /**
     * @param list<string> $tokens
     *
     * @return int|null
     */
    private function firstOutcomeMarkerIndex(array $tokens): ?int
    {
        foreach ($tokens as $index => $token) {
            if (in_array($token, self::OUTCOME_MARKERS, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return string|null
     */
    private function methodVerb(string $token): ?string
    {
        $verb = strtolower($token);
        $verb = self::VERB_ALIASES[$verb] ?? $verb;

        return in_array($verb, self::METHOD_VERBS, true) ? $verb : null;
    }
}
