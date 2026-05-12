<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Waste;

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
 * Detects regular comments that look like disabled PHP code.
 */
final readonly class CommentedOutCodeRule implements RuleInterface
{
    /**
     * Stable rule identifier for commented-out code findings.
     */
    public const ID = 'waste.commented-out-code';

    /**
     * Describe the commented-out code rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Commented-out code',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find comment tokens that appear to contain disabled executable code.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for suspicious comment blocks.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $findings   = [];

        foreach ($unit->tokens as $token) {
            if (!$this->isCommentToken($token)) {
                continue;
            }

            $text = $token->text;
            $line = $token->line;

            if (str_starts_with(trim($text), '/**')) {
                continue;
            }

            $content = $this->stripCommentMarkers($text);

            if ($this->isCodeLike($content)) {
                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     'Comment appears to contain commented-out code.',
                    filePath:    $unit->file->displayPath,
                    line:        $line,
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    remediation: 'Remove commented-out code or restore it if still needed.',
                );
            }
        }

        return $findings;
    }

    /**
     * Check whether a parser token is a regular comment.
     *
     * @return bool True for non-docblock comment tokens.
     */
    private function isCommentToken(Token $token): bool
    {
        return $token->id === T_COMMENT;
    }

    /**
     * Remove PHP comment delimiters before code-shape checks.
     *
     * @return string Trimmed comment body.
     */
    private function stripCommentMarkers(string $text): string
    {
        $text = preg_replace('/^\/\*+\s*|\s*\*+\/$/', '', $text) ?? $text;
        $text = preg_replace('/^\s*\*\s?/m', '', $text) ?? $text;
        $text = preg_replace('/^\/\/\s?/m', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Detect whether comment content has enough PHP-like syntax signals.
     *
     * @return bool True when the content looks like disabled code.
     */
    private function isCodeLike(string $content): bool
    {
        if (strlen($content) < 5) {
            return false;
        }

        $lines = array_filter(explode("\n", $content), static fn (string $line): bool => trim($line) !== '');

        if ($lines === []) {
            return false;
        }

        $codeIndicators = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_contains($trimmed, ';') && preg_match('/\$\w/', $trimmed) === 1) {
                $codeIndicators++;
            }

            if (preg_match('/^(if|for|foreach|while|return|throw|echo)\s*\(/', $trimmed) === 1) {
                $codeIndicators++;
            }

            if (preg_match('/\$\w+\s*->\s*\w+\s*\(/', $trimmed) === 1) {
                $codeIndicators++;
            }

            if (preg_match('/\$\w+\s*=\s*/', $trimmed) === 1) {
                $codeIndicators++;
            }
        }

        return $codeIndicators >= 2;
    }
}
