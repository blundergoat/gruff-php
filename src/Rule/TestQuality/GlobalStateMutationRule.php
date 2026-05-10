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
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final readonly class GlobalStateMutationRule implements RuleInterface
{
    public const ID = 'test-quality.global-state-mutation';

    private const SUPERGLOBALS = ['_GET', '_POST', '_REQUEST', '_SERVER', '_ENV', '_COOKIE', '_FILES', 'GLOBALS'];
    private const STATE_FUNCTIONS = ['putenv', 'ini_set', 'error_reporting', 'date_default_timezone_set'];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Global state mutation in test',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];
        $cleanupCache = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            if ($scope->isPest) {
                continue;
            }

            $class = $scope->node->getAttribute('parent');
            if (!$class instanceof Stmt\Class_) {
                continue;
            }

            $classKey = spl_object_id($class);
            if (!array_key_exists($classKey, $cleanupCache)) {
                $cleanupCache[$classKey] = $this->classHasCleanup($class);
            }

            if ($cleanupCache[$classKey]) {
                continue;
            }

            foreach ($finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\Assign) as $assign) {
                if (!$assign instanceof Expr\Assign) {
                    continue;
                }

                $superglobal = $this->superglobalWriteName($assign->var);
                if ($superglobal === null) {
                    continue;
                }

                $findings[] = $this->finding(
                    $unit,
                    $scope,
                    $assign->getStartLine(),
                    sprintf('%s writes to $%s without a tearDown / #[After] cleanup.', $scope->symbol, $superglobal),
                    ['variant' => 'superglobal', 'name' => $superglobal],
                );
            }

            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                if (!$call instanceof Expr\FuncCall) {
                    continue;
                }

                $name = TestQualityNodeHelper::functionName($call);
                if ($name === null || !in_array($name, self::STATE_FUNCTIONS, true)) {
                    continue;
                }

                $findings[] = $this->finding(
                    $unit,
                    $scope,
                    $call->getStartLine(),
                    sprintf('%s calls %s() without a tearDown / #[After] cleanup.', $scope->symbol, $name),
                    ['variant' => 'function', 'name' => $name],
                );
            }
        }

        return $findings;
    }

    private function superglobalWriteName(Expr $target): ?string
    {
        if (!$target instanceof Expr\ArrayDimFetch) {
            return null;
        }

        $variable = $target->var;
        while ($variable instanceof Expr\ArrayDimFetch) {
            $variable = $variable->var;
        }

        if (!$variable instanceof Expr\Variable || !is_string($variable->name)) {
            return null;
        }

        return in_array($variable->name, self::SUPERGLOBALS, true) ? $variable->name : null;
    }

    private function classHasCleanup(Stmt\Class_ $class): bool
    {
        foreach ($class->getMethods() as $method) {
            $methodName = strtolower($method->name->toString());

            if (in_array($methodName, ['teardown', 'teardownafterclass'], true)) {
                return true;
            }

            $doc = strtolower($method->getDocComment()?->getText() ?? '');
            if (str_contains($doc, '@after') || str_contains($doc, '@afterclass')) {
                return true;
            }

            if (TestQualityNodeHelper::hasAttribute($method, 'After')
                || TestQualityNodeHelper::hasAttribute($method, 'AfterClass')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, scalar> $metadata
     */
    private function finding(
        AnalysisUnit $unit,
        TestQualityScope $scope,
        int $line,
        string $message,
        array $metadata,
    ): Finding {
        return new Finding(
            ruleId: self::ID,
            message: $message,
            filePath: $unit->file->displayPath,
            line: $line,
            severity: Severity::Warning,
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            confidence: Confidence::Medium,
            symbol: $scope->symbol,
            remediation: 'Reset the value in tearDown / #[After] or scope the change to a fixture-managed environment so the next test starts clean.',
            metadata: $metadata,
        );
    }
}
