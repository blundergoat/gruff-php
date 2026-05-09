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

final readonly class MysteryGuestRule implements RuleInterface
{
    public const ID = 'test-quality.mystery-guest';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Mystery guest',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach ($finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\FuncCall || $node instanceof Expr\New_) as $node) {
                $guest = $this->mysteryGuest($node);
                if ($guest === null) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId: self::ID,
                    message: sprintf('%s reaches out to %s from inside the test body.', $scope->symbol, $guest),
                    filePath: $unit->file->displayPath,
                    line: $node->getStartLine(),
                    severity: Severity::Advisory,
                    pillar: Pillar::TestQuality,
                    tier: RuleTier::V01,
                    confidence: Confidence::Medium,
                    symbol: $scope->symbol,
                    remediation: 'Make external files or database fixtures explicit in setup, or replace them with inline test data.',
                    metadata: ['guest' => $guest],
                );
            }
        }

        return $findings;
    }

    private function mysteryGuest(Node $node): ?string
    {
        if ($node instanceof Expr\FuncCall) {
            $name = TestQualityNodeHelper::functionName($node);

            return in_array($name, ['file_get_contents', 'fopen', 'file', 'parse_ini_file', 'mysqli_connect'], true)
                ? (string) $name
                : null;
        }

        if ($node instanceof Expr\New_ && $node->class instanceof Name) {
            $class = strtolower($node->class->toString());

            return in_array($class, ['pdo', 'mysqli'], true) ? $node->class->toString() : null;
        }

        return null;
    }
}
