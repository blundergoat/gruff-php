<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

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
     * Describes the debug-mode rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
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
     * Reports each `ini_set()` call that forces error display on.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per literal display-errors toggle in the parsed unit.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every function call in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            // Only a global ini_set() can flip an error-display directive.
            if (SecurityNodeHelper::globalFunctionName($call) !== 'ini_set') {
                continue;
            }

            $directive = SecurityNodeHelper::argumentValue($call->args, 0);
            // Only a literal display_errors / display_startup_errors directive is in scope.
            if (!$directive instanceof Scalar\String_ || !in_array(strtolower($directive->value), self::DISPLAY_DIRECTIVES, true)) {
                continue;
            }

            $directiveValue = SecurityNodeHelper::argumentValue($call->args, 1);
            // A falsy or absent value leaves error display off, which is the safe state.
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

        return $findings;
    }

    /**
     * Reports whether a literal argument turns the directive on.
     *
     * @param Expr $directiveValue - Second argument to ini_set.
     *
     * @return bool - True when the value is a truthy on-literal.
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
