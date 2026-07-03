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
 * Detects tests that reach hidden external fixtures or global state.
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
     * Describe the mystery guest test rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        );
    }

    /**
     * Find tests that reach external files or databases from inside the test body.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for hidden external test dependencies.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (TestQualityNodeHelper::testScopes($analysisUnit) as $scope) {
            // User view: add each item that can appear in findings list.
            foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\FuncCall::class, Expr\New_::class]) as $node) {
                $guest = $this->mysteryGuest($node);
                // User view: choose the findings list branch for this case.
                // User view: missing data becomes the expected findings list state.
                if ($guest === null) {
                    continue;
                }

                // User view: choose the findings list branch for this case.
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
     * Identify external file/database access in a call or constructor node.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Call or constructor node from a test body to classify.
     *
     * @return string|null - Guest dependency name, or null when none is detected.
     */
    private function mysteryGuest(Node $node): ?string
    {
        // User view: choose the findings list branch for this case.
        if ($node instanceof Expr\FuncCall) {
            $name = TestQualityNodeHelper::functionName($node);

            // Name the read function as the guest only when it is one of the tracked filesystem/DB reads.
            return in_array($name, self::READ_FUNCTIONS, true)
                ? (string) $name
                : null;
        }

        // User view: choose the findings list branch for this case.
        if ($node instanceof Expr\New_ && $node->class instanceof Name) {
            $class = strtolower($node->class->toString());

            // A direct PDO/mysqli construction opens an external connection, so report it as the guest.
            return in_array($class, ['pdo', 'mysqli'], true) ? $node->class->toString() : null;
        }

        // Neither a tracked read call nor a database connection, so this node hides no external fixture.
        return null;
    }

    /**
     * Detect reads from paths the test created or handed to the SUT earlier in the same test.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param TestQualityScope $scope - Enclosing test scope whose earlier statements may have prepared the path.
     * @param Node             $node - Read node under suspicion of reaching a hidden fixture.
     *
     * @return bool - True when the file access is test-owned rather than a hidden fixture.
     */
    private function usesPreparedPath(TestQualityScope $scope, Node $node): bool
    {
        $path = $this->readPathExpression($node);
        // User view: choose the findings list branch for this case.
        if (!$path instanceof Expr) {
            // A non-path guest (such as a database connection) can never be a prepared path, so do not exempt it.
            return false;
        }

        $pathKeys = $this->pathKeys($path);
        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($pathKeys === []) {
            // A dynamic path we cannot key gets no benefit of the doubt; treat it as a potential hidden fixture.
            return false;
        }

        $readerLine = $node->getStartLine();
        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::descendantsOfAny($scope->node, [Expr\FuncCall::class, Expr\MethodCall::class, Expr\StaticCall::class, Expr\New_::class]) as $candidate) {
            // User view: choose the findings list branch for this case.
            if ($candidate->getStartLine() >= $readerLine) {
                continue;
            }

            $preparedKeys = $this->preparedPathKeys($candidate);
            // User view: choose the findings list branch for this case.
            // User view: an empty value becomes a clear findings list fallback.
            if (array_intersect($pathKeys, $preparedKeys) !== []) {
                // An earlier call wrote or handled this same path, so the read is explicit test-owned setup.
                return true;
            }
        }

        // No earlier statement prepared this path, so the read still looks like a hidden external fixture.
        return false;
    }

    /**
     * Return the path argument from a file-read call.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Guest node whose first argument may carry the read path.
     *
     * @return Expr|null - Path expression, or null for non-path guests.
     */
    private function readPathExpression(Node $node): ?Expr
    {
        // User view: choose the findings list branch for this case.
        if (!$node instanceof Expr\FuncCall) {
            // Only plain function calls take a path argument; constructors and other nodes have no path here.
            return null;
        }

        $name = TestQualityNodeHelper::functionName($node);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($name === null || !in_array($name, self::READ_FUNCTIONS, true) || $name === 'mysqli_connect') {
            // mysqli_connect takes a host, not a filesystem path, so it has no path expression to compare.
            return null;
        }

        // User view: missing data becomes a safe findings list default.
        $arg = $node->args[0] ?? null;

        // The first argument holds the path for every tracked read; a spread/missing arg yields no path.
        return $arg instanceof Arg ? $arg->value : null;
    }

    /**
     * Collect path-identifying keys prepared by a prior setup/SUT call.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall|Expr\New_ $node - Earlier call that may have created or
     *                                                                      received the path the later read consumes.
     *
     * @return list<string> - Path keys.
     */
    private function preparedPathKeys(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall|Expr\New_ $node): array
    {
        // User view: choose the findings list branch for this case.
        if ($node instanceof Expr\FuncCall) {
            $name = TestQualityNodeHelper::functionName($node);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($name !== null && isset(self::WRITE_FUNCTION_TARGET_ARG[$name])) {
                // User view: missing data becomes a safe findings list default.
                $arg = $node->args[self::WRITE_FUNCTION_TARGET_ARG[$name]] ?? null;

                // A write keys only its destination argument, so a later read of that path counts as prepared.
                return $arg instanceof Arg ? $this->pathKeys($arg->value) : [];
            }

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($name !== null && in_array($name, self::READ_FUNCTIONS, true)) {
                // An earlier read does not prepare a path for a later read, so it contributes no keys.
                return [];
            }
        }

        // User view: choose the findings list branch for this case.
        if (($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)
            && (TestQualityNodeHelper::isAssertionCall($node) || TestQualityNodeHelper::isMockCreationCall($node) || TestQualityNodeHelper::isMockVerificationCall($node))) {
            // Assertions and mock plumbing never write fixtures, so their path-shaped arguments must not exempt a read.
            return [];
        }

        // Any other prior call (setup helper or SUT) may have been handed the path, so harvest all of its arguments.
        return $this->argumentPathKeys(array_values($node->args));
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<Arg|Node\VariadicPlaceholder> $args - Call arguments to inspect.
     *
     * @return list<string> - Path keys found in arguments.
     */
    private function argumentPathKeys(array $args): array
    {
        $keys = [];

        // User view: add each item that can appear in findings list.
        foreach ($args as $arg) {
            // User view: choose the findings list branch for this case.
            if (!$arg instanceof Arg) {
                continue;
            }

            array_push($keys, ...$this->pathKeys($arg->value));
        }

        // De-duplicate so a path repeated across arguments contributes one key to the intersection test.
        return array_values(array_unique($keys));
    }

    /**
     * Build stable keys for simple literal, variable, concatenated, and array path expressions.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr $expression - Path expression to reduce to comparable keys; recursion handles concat and array forms.
     *
     * @return list<string> - Keys such as `var:outputPath` or `literal:/tmp/file.json`.
     */
    private function pathKeys(Expr $expression): array
    {
        // User view: choose the findings list branch for this case.
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            // Key a variable by name so a read and an earlier write through the same $var line up.
            return ['var:' . $expression->name];
        }

        // User view: choose the findings list branch for this case.
        if ($expression instanceof Scalar\String_) {
            // Key a literal by its exact value so identical hard-coded paths match across statements.
            return ['literal:' . $expression->value];
        }

        // User view: choose the findings list branch for this case.
        if ($expression instanceof Expr\BinaryOp\Concat) {
            // A built-up path matches if either side overlaps, so fold both operands' keys together.
            return array_values(array_unique([
                ...$this->pathKeys($expression->left),
                ...$this->pathKeys($expression->right),
            ]));
        }

        // User view: choose the findings list branch for this case.
        if ($expression instanceof Expr\Array_) {
            $keys = [];
            // User view: add each item that can appear in findings list.
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
