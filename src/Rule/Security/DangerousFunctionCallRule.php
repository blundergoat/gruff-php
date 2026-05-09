<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

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
use PhpParser\NodeFinder;

final class DangerousFunctionCallRule implements RuleInterface
{
    public const ID = 'security.dangerous-function-call';

    /**
     * @var list<string>
     */
    private const DANGEROUS_FUNCTIONS = [
        'exec',
        'passthru',
        'popen',
        'proc_open',
        'shell_exec',
        'system',
    ];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Dangerous function calls',
            pillar: Pillar::Security,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if ($name === null) {
                if (!$call->name instanceof Node\Name) {
                    $findings[] = $this->finding($unit, $call, 'dynamic function call');
                }

                continue;
            }

            if (in_array($name, self::DANGEROUS_FUNCTIONS, true)) {
                $findings[] = $this->finding($unit, $call, $name);
            }

            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            if ($name === 'assert' && $firstArg !== null && SecurityNodeHelper::isStringLiteral($firstArg)) {
                $findings[] = $this->finding($unit, $call, 'assert string evaluation');
            }
        }

        foreach ($finder->findInstanceOf($unit->statements, Expr\Eval_::class) as $eval) {
            $findings[] = $this->finding($unit, $eval, 'eval');
        }

        return $findings;
    }

    private function finding(AnalysisUnit $unit, Node $node, string $function): Finding
    {
        return new Finding(
            ruleId: self::ID,
            message: sprintf('Dangerous PHP execution pattern detected: %s.', $function),
            filePath: $unit->file->displayPath,
            line: $node->getStartLine(),
            severity: Severity::Warning,
            pillar: Pillar::Security,
            tier: RuleTier::V01,
            confidence: Confidence::Medium,
            remediation: 'Replace direct execution with a constrained wrapper, strict allow-lists, or a non-shell API.',
            metadata: [
                'function' => $function,
            ],
        );
    }
}
