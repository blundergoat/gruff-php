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
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * Flags a "mystery guest" - a test that reads an external file or opens a database from inside its own body
 * (`file_get_contents`, `new PDO`, ...), pulling in fixtures a reader cannot see, so the test's outcome
 * hinges on hidden state. Paths the test itself prepared are exempt. Runs over every test. Advisory, medium confidence.
 */
final readonly class MysteryGuestRule implements RuleInterface
{
    /**
     * Stable rule identifier for mystery guest findings.
     */
    public const ID = 'test-quality.mystery-guest';

    /**
     * File/database reads that can hide external fixtures.
     */
    private const READ_FUNCTIONS = [
        'file_get_contents',
        'file_exists',
        'fopen',
        'file',
        'is_file',
        'parse_ini_file',
        'mysqli_connect',
    ];

    /**
     * File writes that make a later read explicit test-owned setup.
     */
    private const WRITE_FUNCTION_TARGET_ARG = [
        'file_put_contents' => 0,
        'mkdir' => 0,
        'copy' => 1,
    ];

    /**
     * Describes the mystery-guest rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory by default: a hidden fixture is a smell worth flagging but rarely a hard test failure.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Mystery guest',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape'      => 'A test reading a fixture the suite prepared elsewhere, for example in setUp() or a shared trait rather than in the test body.',
                    'mitigation' => 'Only paths the test itself prepared are exempt, so create the file inside the test body or accept the advisory.',
                ],
            ],
        );
    }

    /**
     * Reports tests that reach external files or databases from inside the test body.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for hidden external test dependencies.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Weigh every test scope in the file.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // Weigh every call and construction in the test body.
            foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\FuncCall::class, Expr\New_::class]) as $node) {
                $guest = $this->mysteryGuest($node);
                // Skip nodes that reach no external file or database.
                if ($guest === null) {
                    continue;
                }

                // A path the test prepared earlier is explicit setup, not a hidden guest.
                if ($this->usesPreparedPath($scope, $node)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId:      self::ID,
                    message:     sprintf('%s reaches out to %s from inside the test body.', $scope->symbol, $guest),
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $node->getStartLine(),
                    severity:    Severity::Advisory,
                    pillar:      Pillar::TestQuality,
                    tier:        RuleTier::V01,
                    confidence:  Confidence::Medium,
                    symbol:      $scope->symbol,
                    remediation: 'Make external files or database fixtures explicit in setup, or replace them with inline test data.',
                    metadata:    ['guest' => $guest],
                );
            }
        }

        return $findings;
    }

    /**
     * Returns the name of an external file/database dependency in a node, or null.
     *
     * @param Node $node - Call or constructor node from a test body to classify.
     *
     * @return string|null - Guest dependency name, or null when none is detected.
     */
    private function mysteryGuest(Node $node): ?string
    {
        // A tracked read function names the external file it touches.
        if ($node instanceof Expr\FuncCall) {
            $name = TestQualityNodeHelper::functionName($node);

            // Name the read function as the guest only when it is one of the tracked filesystem/DB reads.
            return in_array($name, self::READ_FUNCTIONS, true)
                ? (string) $name
                : null;
        }

        // A direct PDO/mysqli construction opens an external connection.
        if ($node instanceof Expr\New_ && $node->class instanceof Name) {
            $class = strtolower($node->class->toString());

            // A direct PDO/mysqli construction opens an external connection, so report it as the guest.
            return in_array($class, ['pdo', 'mysqli'], true) ? $node->class->toString() : null;
        }

        // Neither a tracked read call nor a database connection, so this node hides no external fixture.
        return null;
    }

    /**
     * Reports whether a read uses a path the test itself prepared earlier.
     *
     * @param TestQualityScope $scope - Enclosing test scope whose earlier statements may have prepared the path.
     * @param Node             $node - Read node under suspicion of reaching a hidden fixture.
     *
     * @return bool - True when the file access is test-owned rather than a hidden fixture.
     */
    private function usesPreparedPath(TestQualityScope $scope, Node $node): bool
    {
        $path = $this->readPathExpression($node);
        if (!$path instanceof Expr) {
            // A non-path guest (such as a database connection) can never be a prepared path, so do not exempt it.
            return false;
        }

        $pathKeys = $this->pathKeys($path);
        if ($pathKeys === []) {
            // A dynamic path we cannot key gets no benefit of the doubt; treat it as a potential hidden fixture.
            return false;
        }

        $readerLine = $node->getStartLine();
        // Look for an earlier statement that prepared this path.
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\FuncCall::class, Expr\MethodCall::class, Expr\StaticCall::class, Expr\New_::class]) as $candidate) {
            // Only statements before the read can have prepared its path.
            if ($candidate->getStartLine() >= $readerLine) {
                continue;
            }

            $preparedKeys = $this->preparedPathKeys($candidate);
            if (array_intersect($pathKeys, $preparedKeys) !== []) {
                // An earlier call wrote or handled this same path, so the read is explicit test-owned setup.
                return true;
            }
        }

        // No earlier statement prepared this path, so the read still looks like a hidden external fixture.
        return false;
    }

    /**
     * Returns the path argument of a file-read call, or null for non-path guests.
     *
     * @param Node $node - Guest node whose first argument may carry the read path.
     *
     * @return Expr|null - Path expression, or null for non-path guests.
     */
    private function readPathExpression(Node $node): ?Expr
    {
        if (!$node instanceof Expr\FuncCall) {
            // Only plain function calls take a path argument; constructors and other nodes have no path here.
            return null;
        }

        $name = TestQualityNodeHelper::functionName($node);
        if ($name === null || !in_array($name, self::READ_FUNCTIONS, true) || $name === 'mysqli_connect') {
            // mysqli_connect takes a host, not a filesystem path, so it has no path expression to compare.
            return null;
        }

        $arg = $node->args[0] ?? null;

        // The first argument holds the path for every tracked read; a spread/missing arg yields no path.
        return $arg instanceof Arg ? $arg->value : null;
    }

    /**
     * Collects the path keys a prior setup or SUT call prepared.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall|Expr\New_ $node - Earlier call that may have created or
     *                                                                      received the path the later read consumes.
     *
     * @return list<string> - Path keys.
     */
    private function preparedPathKeys(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall|Expr\New_ $node): array
    {
        // A write function keys the path it created.
        if ($node instanceof Expr\FuncCall) {
            $name = TestQualityNodeHelper::functionName($node);
            // A tracked write prepares the path at its destination argument.
            if ($name !== null && isset(self::WRITE_FUNCTION_TARGET_ARG[$name])) {
                $arg = $node->args[self::WRITE_FUNCTION_TARGET_ARG[$name]] ?? null;

                // A write keys only its destination argument, so a later read of that path counts as prepared.
                return $arg instanceof Arg ? $this->pathKeys($arg->value) : [];
            }

            if ($name !== null && in_array($name, self::READ_FUNCTIONS, true)) {
                // An earlier read does not prepare a path for a later read, so it contributes no keys.
                return [];
            }
        }

        if (($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)
            && (TestQualityNodeHelper::isAssertionCall($node) || TestQualityNodeHelper::isMockCreationCall($node) || TestQualityNodeHelper::isMockVerificationCall($node))) {
            // Assertions and mock plumbing never write fixtures, so their path-shaped arguments must not exempt a read.
            return [];
        }

        // Any other prior call (setup helper or SUT) may have been handed the path, so harvest all of its arguments.
        return $this->argumentPathKeys(array_values($node->args));
    }

    /**
     * Collects the path keys found across a call's arguments.
     *
     * @param list<Arg|Node\VariadicPlaceholder> $args - Call arguments to inspect.
     *
     * @return list<string> - Path keys found in arguments.
     */
    private function argumentPathKeys(array $args): array
    {
        $keys = [];

        // Weigh each argument for a path key.
        foreach ($args as $arg) {
            // Skip spread placeholders, which carry no plain value.
            if (!$arg instanceof Arg) {
                continue;
            }

            array_push($keys, ...$this->pathKeys($arg->value));
        }

        // De-duplicate so a path repeated across arguments contributes one key to the intersection test.
        return array_values(array_unique($keys));
    }

    /**
     * Builds stable comparison keys for a path expression.
     *
     * @param Expr $expression - Path expression to reduce to comparable keys; recursion handles concat and array forms.
     *
     * @return list<string> - Keys such as `var:outputPath` or `literal:/tmp/file.json`.
     */
    private function pathKeys(Expr $expression): array
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            // Key a variable by name so a read and an earlier write through the same $var line up.
            return ['var:' . $expression->name];
        }

        if ($expression instanceof Scalar\String_) {
            // Key a literal by its exact value so identical hard-coded paths match across statements.
            return ['literal:' . $expression->value];
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            // A built-up path matches if either side overlaps, so fold both operands' keys together.
            return array_values(array_unique([
                ...$this->pathKeys($expression->left),
                ...$this->pathKeys($expression->right),
            ]));
        }

        if ($expression instanceof Expr\Array_) {
            $keys = [];
            // Fold in every element's path keys.
            foreach ($expression->items as $arrayItem) {
                array_push($keys, ...$this->pathKeys($arrayItem->value));
            }

            // An array of paths (such as copy targets) contributes every element's keys, de-duplicated.
            return array_values(array_unique($keys));
        }

        // Function calls, property fetches, and other dynamic shapes have no stable key, so they match nothing.
        return [];
    }
}
