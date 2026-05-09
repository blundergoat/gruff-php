<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;

final readonly class TodoDensityRule implements RuleInterface
{
    public const ID = 'docs.todo-density';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'TODO/FIXME density',
            pillar: Pillar::Documentation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::High,
            defaultThresholds: [
                'warning' => 5,
                'error' => 10,
            ],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings = $context->settingsFor($definition);
        $warningThreshold = $settings->numericThreshold('warning');
        $errorThreshold = $settings->numericThreshold('error');

        $count = 0;
        $firstLine = null;

        if (preg_match_all('/\b(TODO|FIXME|HACK|XXX)\b/i', $unit->source, $matches, PREG_OFFSET_CAPTURE)) {
            $count = count($matches[0]);

            if ($count > 0) {
                $offset = $matches[0][0][1];
                $firstLine = substr_count($unit->source, "\n", 0, $offset) + 1;
            }
        }

        if ($count <= $warningThreshold) {
            return [];
        }

        $severity = $count > $errorThreshold ? Severity::Error : Severity::Warning;
        $threshold = $severity === Severity::Error ? $errorThreshold : $warningThreshold;

        return [
            new Finding(
                ruleId: $definition->id,
                message: sprintf('File has %d TODO/FIXME markers, above the %s threshold of %s.', $count, $severity->value, (string) (int) $threshold),
                filePath: $unit->file->displayPath,
                line: $firstLine,
                severity: $severity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                remediation: 'Resolve outstanding TODOs or track them as issues.',
                metadata: ['count' => $count, 'threshold' => $threshold, 'thresholdType' => $severity->value],
            ),
        ];
    }
}
