<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * Flags a variable assigned a freshly created mock that the test then never reads - dead scaffolding that
 * suggests a collaborator was mocked and forgotten, or the test drifted from what it set up. Runs over every
 * test in the file. Advisory, high confidence - an unread assignment is unambiguous.
 */
final readonly class UnusedMockRule implements RuleInterface
{
    /**
     * Stable rule identifier for unused mock findings.
     */
    public const ID = 'test-quality.unused-mock';

    /**
     * Describes the unused-mock rule for the registry and reports.
     *
     * @return RuleDefinition - identity, pillar, tier, and the advisory severity plus high confidence callers register
     *                          and surface this rule by
     */
    public function definition(): RuleDefinition
    {
        // Advisory keeps unused-mock cleanup opt-in; high confidence because an unread assignment is unambiguous.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unused mock variable',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports mock variables that are assigned but never read.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one finding per mock created but never read across all test scopes in the unit; empty
     *                         when every mock is used
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            $assignedVarObjectIds = [];
            $mockAssignments      = $this->mockAssignments($scope, $assignedVarObjectIds);

            // Nothing to check when the test creates no mocks.
            if ($mockAssignments === []) {
                continue;
            }

            $findings = array_merge(
                $findings,
                $this->findingsForUnreadMocks($analysisUnit, $scope, $mockAssignments, $this->variableReads($scope, $assignedVarObjectIds)),
            );
        }

        return $findings;
    }

    /**
     * Collects the mock variables created in a test scope.
     *
     * @param TestQualityScope $scope - Test method scope whose assignments are scanned for mock creation.
     * @param array<int, true> $assignedVarObjectIds - Receives, by reference, the object id of every assigned variable
     *                                                 node so reads can later exclude the assignment target itself.
     *
     * @return array<string, array{line: int, name: string}> - mock creations keyed by variable name, each holding the
     *                                                          first assignment line and the variable name; empty when
     *                                                          the scope creates no mocks
     */
    private function mockAssignments(
        TestQualityScope $scope,
        array            &$assignedVarObjectIds,
    ): array {
        $mockAssignments = [];
        $assignments     = NodeIndex::descendantsOfAny($scope->node, [Expr\Assign::class]);

        // Weigh every assignment in the test body.
        foreach ($assignments as $assign) {
            // Only a plainly named variable target can be tracked.
            if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
                continue;
            }

            $assignedVarObjectIds[spl_object_id($assign->var)] = true;

            // Record the target only when the right-hand side creates a mock.
            if (!$this->isMockCreationExpression($assign->expr)) {
                continue;
            }

            $varName                   = $assign->var->name;
            $mockAssignments[$varName] ??= [
                'line' => $assign->getStartLine(),
                'name' => $varName,
            ];
        }

        return $mockAssignments;
    }

    /**
     * Collects the variable reads in a test scope, excluding assignment targets.
     *
     * @param TestQualityScope $scope - Test method scope to scan for variable reads.
     * @param array<int, true> $assignedVarObjectIds - Object ids of assignment-target variable nodes to skip, so the
     *                                                 left-hand side of `$mock = ...` is not mistaken for a read.
     *
     * @return array<string, true> - set keyed by every variable name read somewhere other than its own assignment
     *                               target; absence of a name means that variable is never read
     */
    private function variableReads(TestQualityScope $scope, array $assignedVarObjectIds): array
    {
        $reads = [];

        // Weigh every variable node in the test body.
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\Variable::class]) as $var) {
            // Skip variable-variables with no static name.
            if (!is_string($var->name)) {
                continue;
            }

            // Skip the assignment target itself; that write is not a read.
            if (isset($assignedVarObjectIds[spl_object_id($var)])) {
                continue;
            }

            $reads[$var->name] = true;
        }

        return $reads;
    }

    /**
     * Builds findings for the mocks that were created but never read.
     *
     * @param AnalysisUnit                                  $analysisUnit - Unit supplying the display path for findings.
     * @param TestQualityScope                              $scope - Test scope whose symbol names the offending test.
     * @param array<string, array{line: int, name: string}> $mockAssignments - Mock creations keyed by variable name,
     *                                                                       each carrying the assignment line to report.
     * @param array<string, true>                           $reads - Variable names observed as reads; a mock present here is considered used
     *                                                                       and is therefore not reported.
     *
     * @return list<Finding> - one advisory finding per mock created but never read back; empty when every mock is used
     */
    private function findingsForUnreadMocks(
        AnalysisUnit     $analysisUnit,
        TestQualityScope $scope,
        array            $mockAssignments,
        array            $reads,
    ): array {
        $findings = [];

        // Weigh each mock the test created.
        foreach ($mockAssignments as $varName => $assignment) {
            // A mock that is read somewhere is genuinely used.
            if (isset($reads[$varName])) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('%s creates mock $%s but never reads it.', $scope->symbol, $varName),
                filePath:    $analysisUnit->file->displayPath,
                line:        $assignment['line'],
                severity:    Severity::Advisory,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                symbol:      $scope->symbol,
                remediation: 'Use the mock to set expectations or pass it to the SUT, or remove the unused mock creation.',
                metadata:    ['variable' => $varName],
            );
        }

        return $findings;
    }

    /**
     * Reports whether an expression contains a recognised mock-creation call.
     *
     * @param Expr $expr - Right-hand side of an assignment to test for a nested mock-creation call.
     *
     * @return bool - true as soon as any descendant call is a recognised mock factory, so creators wrapped in other
     *                calls still count; false when no such call is present
     */
    private function isMockCreationExpression(Expr $expr): bool
    {
        $nodeFinder = new NodeFinder();

        $matches = $nodeFinder->find(
            [$expr],
            static fn(Node $node): bool => ($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)
                                           && TestQualityNodeHelper::isMockCreationCall($node),
        );

        // True as soon as any descendant call is a recognised mock factory, so wrapped creators still count.
        return $matches !== [];
    }
}
