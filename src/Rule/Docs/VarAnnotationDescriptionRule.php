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
use PhpParser\Token;

final readonly class VarAnnotationDescriptionRule implements RuleInterface
{
    public const ID = 'docs.var-annotation-description';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Var annotation description',
            pillar: Pillar::Documentation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            description: 'Requires local @var type assertions to explain why the asserted type is needed.',
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $findings = [];

        foreach ($unit->tokens as $index => $token) {
            if ($token->id !== T_DOC_COMMENT || !str_contains($token->text, '@var')) {
                continue;
            }

            if (!$this->isLocalVarAssertion($unit->tokens, $index)) {
                continue;
            }

            foreach ($this->bareVarAnnotations($token->text) as $variable) {
                $findings[] = new Finding(
                    ruleId: $definition->id,
                    message: sprintf('@var assertion for $%s must explain why the asserted type is needed.', $variable),
                    filePath: $unit->file->displayPath,
                    line: $token->line,
                    severity: $definition->defaultSeverity,
                    pillar: $definition->pillar,
                    tier: $definition->tier,
                    confidence: $definition->confidence,
                    symbol: '$' . $variable,
                    remediation: sprintf('Add a short reason after $%s in the @var annotation.', $variable),
                    metadata: ['variable' => $variable],
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<Token> $tokens
     */
    private function isLocalVarAssertion(array $tokens, int $commentIndex): bool
    {
        for ($index = $commentIndex + 1; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if ($this->isTrivia($token)) {
                continue;
            }

            return !$this->isDeclarationToken($token);
        }

        return false;
    }

    private function isTrivia(Token $token): bool
    {
        return in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private function isDeclarationToken(Token $token): bool
    {
        return in_array($token->id, [
            T_ABSTRACT,
            T_CLASS,
            T_CONST,
            T_ENUM,
            T_FINAL,
            T_FUNCTION,
            T_INTERFACE,
            T_PRIVATE,
            T_PROTECTED,
            T_PUBLIC,
            T_READONLY,
            T_STATIC,
            T_TRAIT,
            T_VAR,
        ], true);
    }

    /**
     * @return list<string>
     */
    private function bareVarAnnotations(string $docText): array
    {
        $descriptiveLines = [];
        $bareVariables = [];

        foreach (preg_split('/\R/', $docText) ?: [] as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");

            if ($line === '') {
                continue;
            }

            if (!str_starts_with($line, '@')) {
                $descriptiveLines[] = $line;
                continue;
            }

            if (preg_match('/^@var\b.*?\$(?<variable>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)(?<description>.*)$/u', $line, $matches) !== 1) {
                continue;
            }

            if (trim($matches['description']) !== '') {
                continue;
            }

            $bareVariables[] = $matches['variable'];
        }

        if ($descriptiveLines !== []) {
            return [];
        }

        return $bareVariables;
    }
}
