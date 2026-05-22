<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Detects process command construction from request-controlled data.
 */
final class ProcessCommandConstructionRule implements RuleInterface
{
    /**
     * Stable rule identifier for request-controlled process commands.
     */
    public const ID = 'security.process-command-construction';

    /**
     * Describe the process command construction rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Process command construction',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find process commands that include request-controlled expressions.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for request-controlled process commands.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\ShellExec::class) as $shellExec) {
            if (SecurityNodeHelper::containsUserInput($shellExec)) {
                $findings[] = $this->finding($analysisUnit, $shellExec, 'shell-exec');
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\New_::class) as $new) {
            if (!SecurityNodeHelper::hasMatchingClassName($new->class, ['Symfony\Component\Process\Process', 'Process'])) {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($new->args, 0);
            if ($firstArg !== null && SecurityNodeHelper::containsUserInput($firstArg)) {
                $findings[] = $this->finding($analysisUnit, $new, 'symfony-process');
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $staticCall) {
            if (
                !SecurityNodeHelper::hasMatchingClassName($staticCall->class, ['Symfony\Component\Process\Process', 'Process'])
                || SecurityNodeHelper::methodName($staticCall) !== 'fromshellcommandline'
            ) {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($staticCall->args, 0);
            if ($firstArg !== null && SecurityNodeHelper::containsUserInput($firstArg)) {
                $findings[] = $this->finding($analysisUnit, $staticCall, 'process-shell-commandline');
            }
        }

        return $findings;
    }

    /**
     * Build the process command finding.
     *
     * @return Finding Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $sink): Finding
    {
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('Process command construction with request-controlled data detected: %s.', $sink),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Build process arguments from allow-listed values and avoid shell parsing for request-controlled input.',
            metadata:    [
                'sink' => $sink,
            ],
        );
    }
}
