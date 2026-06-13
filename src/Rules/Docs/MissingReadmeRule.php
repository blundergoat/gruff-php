<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;

/**
 * Detects projects without a root README file.
 */
final class MissingReadmeRule implements RuleInterface
{
    /**
     * Stable identifier for the missing README rule.
     */
    public const ID = 'docs.missing-readme';

    /** @var array<string, bool> */
    private array $readmePresenceByRoot = [];

    /**
     * Tracks whether the project-level finding has already been emitted.
     */
    private bool $emitted = false;

    /**
     * Describe the missing README rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing README',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Emit one finding when the project root has no README.md file.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Missing README finding, or an empty list.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if ($this->emitted) {
            // The project-level finding fires once per run; later units stay silent.
            return [];
        }

        $root          = $ruleContext->projectRoot;
        $readmePresent = $this->readmePresenceByRoot[$root]
            ??= file_exists($root . '/README.md');

        if ($readmePresent) {
            return [];
        }

        $this->emitted = true;
        $definition    = $this->definition();

        return [
            new Finding(
                ruleId:      $definition->id,
                message:     'Project root has no README.md.',
                filePath:    'README.md',
                line:        null,
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                remediation: 'Add a README.md describing the project.',
            ),
        ];
    }
}
