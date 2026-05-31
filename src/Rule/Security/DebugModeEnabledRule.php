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
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Flags code that forces PHP error display on, leaking diagnostics to users.
 *
 * Detects `ini_set('display_errors', <truthy>)` and the startup-errors variant
 * as committed-code debug switches. The check is pure syntax (no config or
 * container parsing); environment-driven toggles like `APP_DEBUG` live in
 * `.env` and are out of scope.
 */
final class DebugModeEnabledRule implements RuleInterface
{
    /** Stable rule identifier for debug-mode findings. */
    public const ID = 'security.debug-mode-enabled';

    /**
     * `ini_set` directives that expose PHP errors to the response.
     *
     * @var list<string>
     */
    private const DISPLAY_DIRECTIVES = ['display_errors', 'display_startup_errors'];

    /**
     * Describe the debug-mode rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // The registry needs warning-level metadata because forced error display leaks diagnostics.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Debug error display enabled',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            description:     'Forces PHP error display on via ini_set(display_errors, ...), leaking diagnostics in production.',
            falsePositiveShapes: [
                [
                    'shape'      => 'Dev/test bootstrap intentionally enables error display.',
                    'mitigation' => 'Gate the toggle behind an environment check, or disable the rule for bootstrap paths.',
                ],
            ],
        );
    }

    /**
     * Find ini_set calls that turn error display on.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Debug-mode findings.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            if (SecurityNodeHelper::globalFunctionName($call) !== 'ini_set') {
                continue;
            }

            $directive = SecurityNodeHelper::argumentValue($call->args, 0);
            if (!$directive instanceof Scalar\String_ || !in_array(strtolower($directive->value), self::DISPLAY_DIRECTIVES, true)) {
                continue;
            }

            $directiveValue = SecurityNodeHelper::argumentValue($call->args, 1);
            if ($directiveValue === null || !$this->isTruthy($directiveValue)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf("ini_set('%s', ...) forces PHP error display on; this leaks diagnostics to users in production.", strtolower($directive->value)),
                filePath:    $analysisUnit->file->displayPath,
                line:        $call->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Leave display_errors off in production and log errors instead; gate any error-display toggle behind an explicit non-production environment check.',
                metadata:    [
                    'directive' => strtolower($directive->value),
                ],
            );
        }

        // Every finding represents a literal display-errors toggle in this parsed unit.
        return $findings;
    }

    /**
     * Decide whether a literal argument turns the directive on.
     *
     * @param Expr $directiveValue Second argument to ini_set.
     * @return bool True when the value is a truthy on-literal.
     */
    private function isTruthy(Expr $directiveValue): bool
    {
        if ($directiveValue instanceof Scalar\String_) {
            // Literal string switches are the common ini_set shape for enabling display.
            return in_array(strtolower($directiveValue->value), ['1', 'on', 'true', 'yes', 'stdout', 'stderr'], true);
        }

        if ($directiveValue instanceof Scalar\Int_) {
            // Non-zero integer literals are PHP truthy toggles for ini settings.
            return $directiveValue->value !== 0;
        }

        if ($directiveValue instanceof Expr\ConstFetch) {
            // Only the true constant clearly enables display in static syntax.
            return strtolower($directiveValue->name->toString()) === 'true';
        }

        // Unknown expressions are not treated as enabled without static proof.
        return false;
    }
}
