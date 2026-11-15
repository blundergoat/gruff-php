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
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * Flags supported PHPUnit test names when no visible call name matches an inferred method-name prefix.
 * Uses camelCase names and a verb list; assertions are required, and subprocess hints exempt the test.
 * Advisory, low confidence: matching does not establish the target object's type or actual execution.
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
     * @var list<string> - Exact case-sensitive boundaries for the method-name phrase.
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
     * @var list<string> - Lowercase verb forms accepted by the SUT-name heuristic.
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
     * @var array<string, string> - Third-person forms mapped to canonical lowercase verbs.
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
     * Describes the sut-not-called rule for the registry and reports.
     *
     * @return RuleDefinition - rule identity, pillar, tier, and the low-confidence advisory default
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                  self::ID,
            name:                'Test name mentions SUT that is not called',
            pillar:              Pillar::TestQuality,
            tier:                RuleTier::V01,
            defaultSeverity:     Severity::Advisory,
            confidence:          Confidence::Low,
            isEnabledByDefault:  false,
            falsePositiveShapes: [
                [
                    'shape' => 'A test that does exercise the named behaviour but reaches it through a differently named entry point, such as __invoke() or a facade that forwards to the method.',
                    'mitigation' => 'The heuristic matches visible call prefixes, so review whether the named behavior is exercised through an indirect entry point.',
                ],
                [
                    'shape' => 'A test whose name begins with a recognised verb that is prose rather than a method, so an unrelated word is inferred as the system under test.',
                    'mitigation' => 'Candidates are derived from camelCase tokens before the first outcome marker, so rename the test so its verb phrase matches the method it calls.',
                ],
            ],
        );
    }

    /**
     * Reports supported PHPUnit test names with no matching visible call-name prefix.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Required by the rule interface; unused by this rule.
     *
     * @return list<Finding> - One finding per eligible test lacking a match; skipped tests contribute none.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $candidates = $this->candidateSutNames($scope->name);
            // Skip Pest scopes, names with no inferable SUT, and tests that assert nothing.
            if ($scope->isPest || $candidates === [] || TestQualityNodeHelper::assertionCalls($scope) === []) {
                continue;
            }

            // A subprocess test may run the SUT off the AST graph, so treat it as covered.
            if ($this->invokesSubprocess($scope)) {
                continue;
            }

            // A name match is enough for this heuristic; it does not verify the receiver or execution.
            if ($this->hasNamedSutCall($scope, $candidates)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s name implies a SUT behavior, but no matching method call was detected.', $scope->symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->anchorLine(),
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
     * Checks whether an eligible call name shares a normalized prefix with an inferred SUT name.
     *
     * @param TestQualityScope $scope - Test body scanned for call names; recognized assertion and mock helper names can be skipped.
     * @param list<string> $candidates - Normalized SUT names compared with each eligible call name.
     *
     * @return bool - True when either normalized name is a prefix of the other; false when no eligible call matches.
     */
    private function hasNamedSutCall(TestQualityScope $scope, array $candidates): bool
    {

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            // Assertions and mock plumbing are not SUT calls.
            if (TestQualityNodeHelper::isAssertionCall($call) || TestQualityNodeHelper::isMockCreationCall($call) || TestQualityNodeHelper::isMockVerificationCall($call)) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            // Scenario and outcome words may extend either the test phrase or the called method name.
            if ($name !== null) {
                $normalized = TestQualityNodeHelper::normalizedTestName($name);
                foreach ($candidates as $candidate) {
                    if (str_starts_with($normalized, $candidate) || str_starts_with($candidate, $normalized)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Detects subprocess hints that exempt the test from SUT-call matching.
     *
     * @param TestQualityScope $scope - Test body searched for Process construction or subprocess functions.
     *
     * @return bool - True for a Process/PhpProcess short-name construction or a listed subprocess function call.
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

                    // Short-name matches exempt the test without verifying the class namespace or whether the object runs.
                    return $short === 'process' || $short === 'phpprocess';
                },
            ) !== [];

        if ($hasProcessNew) {
            // A matching Process construction may run the SUT in a subprocess, so the heuristic skips the test.
            return true;
        }


        foreach (TestQualityNodeHelper::calls($scope) as $call) {

            if ($call instanceof Expr\FuncCall) {
                $name = TestQualityNodeHelper::functionName($call);
                if ($name !== null && in_array($name, self::SUBPROCESS_FUNCTIONS, true)) {
                    // A shell/exec-family call can invoke the SUT indirectly; treat the test as covered.
                    return true;
                }
            }
        }

        // No recognized Process construction or subprocess function call was found, so the test stays eligible for the SUT-call check.
        return false;
    }

    /**
     * Lists the SUT method-name candidates inferred from a test method name.
     *
     * @param string $testName - PHPUnit method name; only camelCase names with the test prefix yield candidates.
     *
     * @return list<string> - normalised SUT method-name candidates to match against calls; empty when the name yields no inferable SUT
     */
    private function candidateSutNames(string $testName): array
    {
        if (!str_starts_with($testName, 'test') || str_contains($testName, '_')) {
            // Snake_case or non-test names fall outside this camelCase heuristic; yield no candidates.
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
            // Require an outcome marker after a method phrase to avoid inferring a SUT from an undivided name.
            return [];
        }

        $methodTokens = array_slice($tokens, 0, $markerIndex);
        $verb         = $this->methodVerb($methodTokens[0] ?? '');
        if ($verb === null) {
            // Unrecognised verbs provide too little evidence for this name-based heuristic.
            return [];
        }

        // A lone verb is too broad to imply a specific method, so leave it outside this heuristic.
        if (count($methodTokens) < 2) {
            return [];
        }

        $methodTokens[0] = $verb;
        $candidates      = [TestQualityNodeHelper::normalizedTestName(implode('', $methodTokens))];
        $candidates[]    = TestQualityNodeHelper::normalizedTestName(implode('', array_slice($methodTokens, 0, 2)));

        return array_values(array_unique($candidates));
    }

    /**
     * Splits an identifier into word tokens for test-name heuristics.
     *
     * @param string $identifierName - CamelCase fragment after the test prefix that is split into word tokens.
     *
     * @return list<string> - word and digit tokens in source order with original casing preserved; empty when nothing tokenised
     */
    private function camelCaseTokens(string $identifierName): array
    {
        // Split acronyms, capitalised words and digit runs: parseHTTPResponse2 gives parse, HTTP, Response and 2.
        if (preg_match_all('/[A-Z]+(?=[A-Z][a-z]|\d|$)|[A-Z]?[a-z]+|\d+/', $identifierName, $matches) < 1) {
            return [];
        }

        return $matches[0];
    }

    /**
     * Returns the index of the first outcome-marker token, or null when none is present.
     *
     * @param list<string> $tokens - Case-preserved method-name tokens, matched exactly against OUTCOME_MARKERS.
     *
     * @return int|null - index of the first outcome-marker token splitting method phrase from outcome; null when none is present
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
     * Returns the canonical method verb a token resolves to, or null.
     *
     * @param string $token - Leading name token; matched case-insensitively against known method verbs and aliases.
     *
     * @return string|null - canonical lowercase method verb the token resolves to; null when the token is not a recognised verb
     */
    private function methodVerb(string $token): ?string
    {
        $verb = strtolower($token);
        $verb = self::VERB_ALIASES[$verb] ?? $verb;

        return in_array($verb, self::METHOD_VERBS, true) ? $verb : null;
    }
}
