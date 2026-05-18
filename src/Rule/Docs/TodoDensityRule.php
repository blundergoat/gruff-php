<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Config\SeverityThreshold;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Token;

/**
 * Detects files where TODO and FIXME comments exceed the configured density.
 */
final readonly class TodoDensityRule implements RuleInterface
{
    /**
     * Stable rule identifier for TODO density findings.
     */
    public const ID = 'docs.todo-density';

    /**
     * Describe the TODO density rule.
     *
     * @return RuleDefinition Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                       self::ID,
            name:                     'TODO/FIXME density',
            pillar:                   Pillar::Documentation,
            tier:                     RuleTier::V01,
            defaultSeverity:          Severity::Error,
            confidence:               Confidence::High,
            severityThreshold: new SeverityThreshold(10, Severity::Error),
        );
    }

    /**
     * Count TODO-style markers in comments and report files above threshold.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for excessive TODO density.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings   = $context->settingsFor($definition);

        $count     = 0;
        $firstLine = null;

        foreach ($unit->tokens as $token) {
            if (!$this->isCommentToken($token)) {
                continue;
            }

            $matches = preg_match_all('/\b(TODO|FIXME|HACK|XXX)\b/i', $token->text);

            if ($matches === 0 || $matches === false) {
                continue;
            }

            $count += $matches;
            $firstLine ??= $token->line;
        }
        $thresholdMatch = $settings->highValueThresholdMatch($count);

        if ($thresholdMatch === null) {
            return [];
        }

        return [
            new Finding(
                ruleId:      $definition->id,
                message:     sprintf('File has %d TODO/FIXME markers, above the %s threshold of %s.', $count, $thresholdMatch->severity->value, (string) (int) $thresholdMatch->threshold),
                filePath:    $unit->file->displayPath,
                line:        $firstLine,
                severity:    $thresholdMatch->severity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                remediation: 'Resolve outstanding TODOs or track them as issues.',
                metadata:    ['count' => $count, 'threshold' => $thresholdMatch->threshold, 'thresholdType' => $thresholdMatch->severity->value],
            ),
        ];
    }

    /**
     * Check whether a token is a normal comment or docblock.
     *
     * @return bool True when the token can contain TODO markers.
     */
    private function isCommentToken(Token $token): bool
    {
        return $token->id === T_COMMENT || $token->id === T_DOC_COMMENT;
    }
}
