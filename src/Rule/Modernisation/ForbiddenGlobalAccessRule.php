<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Modernisation;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * Detects direct reads from global request and environment arrays.
 */
final readonly class ForbiddenGlobalAccessRule implements RuleInterface
{
    /**
     * Stable rule identifier for forbidden global access findings.
     */
    public const ID = 'modernisation.forbidden-global-access';

    /**
     * @var list<string>
     */
    private const FORBIDDEN_GLOBALS = ['_GET', '_POST', '_SESSION'];

    /**
     * Describe the forbidden global access rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Forbidden direct global access',
            pillar: Pillar::Modernisation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
        );
    }

    /**
     * Find direct superglobal access outside controller boundaries.
     *
     * @return list<Finding> Findings for forbidden global reads.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        if ($this->isControllerPath($unit->file->displayPath)) {
            return [];
        }

        $finder = new NodeFinder();
        $findings = [];
        $seen = [];

        foreach ($finder->findInstanceOf($unit->statements, Expr\Variable::class) as $variable) {
            if (!is_string($variable->name) || !in_array($variable->name, self::FORBIDDEN_GLOBALS, true)) {
                continue;
            }

            $key = $variable->name . ':' . $variable->getStartLine();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('Direct access to $%s outside a controller boundary.', $variable->name),
                filePath: $unit->file->displayPath,
                line: $variable->getStartLine(),
                severity: Severity::Warning,
                pillar: Pillar::Modernisation,
                tier: RuleTier::V01,
                confidence: Confidence::Medium,
                remediation: 'Pass request/session data through a boundary abstraction instead of reading superglobals in domain code; gruff-php reports only.',
                metadata: [
                    'global' => $variable->name,
                ],
            );
        }

        return $findings;
    }

    /**
     * Check whether a file path is treated as a controller boundary.
     *
     * @return bool True when direct request/session access is allowed.
     */
    private function isControllerPath(string $displayPath): bool
    {
        $normalized = '/' . str_replace('\\', '/', $displayPath);

        return str_contains($normalized, '/Controller/')
            || str_contains($normalized, '/Controllers/')
            || str_ends_with($displayPath, 'Controller.php');
    }
}
