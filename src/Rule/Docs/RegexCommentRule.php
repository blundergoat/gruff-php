<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Requires a one-line explanatory comment before regex matcher calls.
 */
final readonly class RegexCommentRule implements RuleInterface
{
    /** Stable identifier for regex-comment findings. */
    public const ID = 'docs.regex-comment';

    /**
     * Regex function names that require a preceding explanation.
     */
    private const REGEX_FUNCTIONS = ['preg_match'];

    /**
     * Describe the regex-comment rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Regex comment',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  ['functionNames' => self::REGEX_FUNCTIONS],
            description:     'Requires a one-line explanatory comment immediately above configured regex matcher calls such as preg_match().',
        );
    }

    /**
     * Find configured regex matcher calls that lack a preceding comment.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for configured regex functions.
     * @return list<Finding> Findings for uncommented regex calls.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition     = $this->definition();
        $functionNames  = $this->normalisedFunctionNames($ruleContext->settingsFor($definition)->stringListOption('functionNames'));
        $sourceLines    = explode("\n", str_replace(["\r\n", "\r"], "\n", $analysisUnit->source));
        $nodeFinder     = new NodeFinder();
        $regexCallNodes = $nodeFinder->findInstanceOf($analysisUnit->statements, FuncCall::class);
        $findings       = [];

        foreach ($regexCallNodes as $regexCallNode) {
            $functionName = $this->functionName($regexCallNode);
            if ($functionName === null || !in_array($functionName, $functionNames, true)) {
                continue;
            }

            if ($this->hasImmediateCommentAbove($sourceLines, $regexCallNode->getStartLine())) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s() should have a one-line comment above it explaining what the regex checks.', $functionName),
                filePath:    $analysisUnit->file->displayPath,
                line:        $regexCallNode->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $this->symbol($regexCallNode),
                remediation: sprintf('Add a one-line comment immediately above the %s() call explaining the regex intent.', $functionName),
                metadata:    ['function' => $functionName],
            );
        }

        return $findings;
    }

    /**
     * @param list<string> $sourceLines Source lines indexed from zero.
     * @return bool True when the previous physical line is a comment.
     */
    private function hasImmediateCommentAbove(array $sourceLines, int $regexCallLine): bool
    {
        $previousLine = $sourceLines[$regexCallLine - 2] ?? null;
        if ($previousLine === null) {
            return false;
        }

        $trimmedLine = ltrim($previousLine);

        return str_starts_with($trimmedLine, '//')
            || str_starts_with($trimmedLine, '#')
            || (str_starts_with($trimmedLine, '/*') && str_contains($trimmedLine, '*/'));
    }

    /**
     * @return string|null Lowercase function name, or null for dynamic calls.
     */
    private function functionName(FuncCall $funcCall): ?string
    {
        if (!$funcCall->name instanceof Name) {
            return null;
        }

        return strtolower($funcCall->name->toString());
    }

    /**
     * @return string|null Function or method symbol containing the regex call.
     */
    private function symbol(Node $node): ?string
    {
        $parent = $node->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof ClassMethod || $parent instanceof Function_) {
                return CyclomaticComplexityRule::resolveSymbol($parent);
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * @param list<string> $functionNames Configured regex function names.
     * @return list<string> Lowercase regex function names.
     */
    private function normalisedFunctionNames(array $functionNames): array
    {
        return array_values(array_unique(array_map(
            static fn (string $functionName): string => strtolower($functionName),
            $functionNames,
        )));
    }
}
