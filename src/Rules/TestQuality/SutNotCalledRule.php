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
 * Flags a PHPUnit test whose name implies a specific behaviour (`testParsesHeader...`) yet whose body never
 * calls a method matching that name - a hint the test drifted from what it claims to exercise, or that its
 * name overstates its coverage. A camelCase-name heuristic; subprocess tests are exempt. Error severity, low confidence.
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
        'analyses'   => 'analyse',
        'analyzes'   => 'analyze',
        'builds'     => 'build',
        'calculates' => 'calculate',
        'calls'      => 'call',
        'creates'    => 'create',
        'decodes'    => 'decode',
        'detects'    => 'detect',
        'discovers'  => 'discover',
        'encodes'    => 'encode',
        'escapes'    => 'escape',
        'finds'      => 'find',
        'formats'    => 'format',
        'handles'    => 'handle',
        'loads'      => 'load',
        'parses'     => 'parse',
        'processes'  => 'process',
        'reads'      => 'read',
        'records'    => 'record',
        'renders'    => 'render',
        'resolves'   => 'resolve',
        'sends'      => 'send',
        'writes'     => 'write',
    ];

    /**
     * Describes the sut-not-called rule for the registry and reports.
     *
     * @return RuleDefinition - rule identity, pillar, tier, and the low-confidence Error default callers may downgrade
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Test name mentions SUT that is not called',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Error,
            confidence:      Confidence::Low,
            falsePositiveShapes: [
                [
                    'shape'      => 'A test that does exercise the named behaviour but reaches it through a differently named entry point, such as __invoke() or a facade that forwards to the method.',
                    'mitigation' => 'The inferred method name must appear as a literal call name, so call the named method directly or rename the test after the entry point it uses.',
                ],
                [
                    'shape'      => 'A test whose name begins with a recognised verb that is prose rather than a method, so an unrelated word is inferred as the system under test.',
                    'mitigation' => 'Candidates are derived from camelCase tokens before the first outcome marker, so rename the test so its verb phrase matches the method it calls.',
                ],
            ],
        );
    }

    /**
     * Reports tests whose name implies a SUT call that is absent from the body.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per test whose name implies an uncalled SUT; empty when all match or skip
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
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

            // A call matching the inferred SUT name means the test does exercise it.
            if ($this->hasNamedSutCall($scope, $candidates)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s name implies a SUT behavior, but no matching method call was detected.', $scope->symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $scope->anchorLine(),
                severity:    Severity::Error,
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
     * Reports whether the test calls a method matching one of the inferred SUT names.
     *
     * @param TestQualityScope $scope - Test body whose calls are scanned for a SUT invocation.
     * @param list<string>     $candidates - Normalised SUT names any non-assertion call must match.
     *
     * @return bool - true when a non-assertion call resolves to a candidate name (SUT exercised); false keeps it open
     */
    private function hasNamedSutCall(TestQualityScope $scope, array $candidates): bool
    {
        $candidateLookup = array_fill_keys($candidates, true);

        // Weigh every call the test makes.
        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            // Assertions and mock plumbing are not SUT calls.
            if (TestQualityNodeHelper::isAssertionCall($call) || TestQualityNodeHelper::isMockCreationCall($call) || TestQualityNodeHelper::isMockVerificationCall($call)) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            // A call whose name matches a candidate proves the SUT is exercised.
            if ($name !== null && isset($candidateLookup[TestQualityNodeHelper::normalizedTestName($name)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether the test invokes a subprocess that could run the SUT off the AST graph.
     *
     * @param TestQualityScope $scope - Test body searched for Process construction or subprocess functions.
     *
     * @return bool - true when a Process object or shell/exec call may run the SUT off the AST graph (treat as covered)
     */
    private function invokesSubprocess(TestQualityScope $scope): bool
    {
        $nodeFinder = new NodeFinder();

        $hasProcessNew = $nodeFinder->find(
                $scope->statements,
                static function (Node $node): bool {
                    if (!$node instanceof Expr\New_ || !$node->class instanceof Name) {
                        // Not a class instantiation by name, so it cannot be a Process construction.
                        return false;
                    }

                    $short = strtolower($node->class->getLast());

                    // Match Symfony Process or PhpProcess, which run the SUT outside the static call graph.
                    return $short === 'process' || $short === 'phpprocess';
                },
            ) !== [];

        if ($hasProcessNew) {
            // A Process object is built, so assume the SUT may run in the subprocess and skip the test.
            return true;
        }

        // Also weigh the test's calls for a subprocess function.
        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            // Only a global function call can be a subprocess launcher.
            if ($call instanceof Expr\FuncCall) {
                $name = TestQualityNodeHelper::functionName($call);
                if ($name !== null && in_array($name, self::SUBPROCESS_FUNCTIONS, true)) {
                    // A shell/exec-family call can invoke the SUT indirectly; treat the test as covered.
                    return true;
                }
            }
        }

        // No Process object and no subprocess function: the test stays eligible for the SUT-call check.
        return false;
    }

    /**
     * Lists the SUT method-name candidates inferred from a test method name.
     *
     * @param string $testName - PHPUnit method name; only camelCase `test`-prefixed names yield candidates.
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
            // Bare "test" with no trailing phrase names no behaviour, so there is no SUT to infer.
            return [];
        }

        $tokens = $this->camelCaseTokens($afterTest);
        if ($tokens === []) {
            // The remainder produced no word tokens, leaving nothing to interpret as a method phrase.
            return [];
        }

        $markerIndex = $this->firstOutcomeMarkerIndex($tokens);
        if ($markerIndex === null || $markerIndex === 0) {
            // No outcome marker, or one leading the name, means no method phrase precedes it: give up.
            return [];
        }

        $methodTokens = array_slice($tokens, 0, $markerIndex);
        $verb         = $this->methodVerb($methodTokens[0] ?? '');
        if ($verb === null) {
            // The leading word is not a known method verb, so the phrase is prose, not a SUT call.
            return [];
        }

        $candidates = [TestQualityNodeHelper::normalizedTestName(implode('', $methodTokens))];

        // A multi-word phrase also yields the bare leading verb as a candidate.
        if (count($methodTokens) > 1) {
            $candidates[] = TestQualityNodeHelper::normalizedTestName($verb);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Splits an identifier into word tokens for test-name heuristics.
     *
     * @param string $identifierName - CamelCase fragment after the `test` prefix to break into word tokens.
     *
     * @return list<string> - word and digit tokens in source order with original casing preserved; empty when nothing tokenised
     */
    private function camelCaseTokens(string $identifierName): array
    {
        if (preg_match_all('/[A-Z]+(?=[A-Z][a-z]|\d|$)|[A-Z]?[a-z]+|\d+/', $identifierName, $matches) < 1) {
            // The fragment held no word or digit runs to tokenise; signal an empty split.
            return [];
        }

        return $matches[0];
    }

    /**
     * Returns the index of the first outcome-marker token, or null when none is present.
     *
     * @param list<string> $tokens - Method-name tokens in source order, already normalised for marker comparison.
     *
     * @return int|null - index of the first outcome-marker token splitting method phrase from outcome; null when none is present
     */
    private function firstOutcomeMarkerIndex(array $tokens): ?int
    {
        // Scan the tokens for the first outcome marker.
        foreach ($tokens as $index => $token) {
            // The first marker splits the method phrase from the outcome text.
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
