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
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
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
     * Regex function names that require a preceding explanation. Defaults cover the five common
     * PCRE functions; projects can override via `defaultOptions.functionNames` to extend or narrow.
     */
    private const REGEX_FUNCTIONS = [
        'preg_match',
        'preg_match_all',
        'preg_replace',
        'preg_replace_callback',
        'preg_split',
    ];

    /**
     * Function-head docblock substrings that satisfy the comment requirement for any contained
     * regex call. The intent is to skip the per-call comment requirement when the enclosing
     * function-like's docblock already describes the regex behaviour.
     */
    /**
     * Substrings in a docblock that signal it already explains the regex behaviour.
     * `match` was previously included but matches docblocks like "Match the route."
     * far too broadly; dropped so the enclosing function-doc exemption only fires
     * when the docblock actually mentions regex/pattern/preg_, or the specific
     * function name (e.g. `preg_match_all`) which is added at lookup time.
     *
     * @var list<string>
     */
    private const FUNCTION_DOC_KEYWORDS = ['regex', 'pattern', 'preg_'];

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
            description:     'Requires a one-line explanatory comment immediately above configured PCRE calls (preg_match, preg_match_all, preg_replace, preg_replace_callback, preg_split by default). Exempt when the call is inside a `match (true) { ... => "label" }` arm with a string-literal label, or when the enclosing function-like docblock references the regex behaviour.',
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
        $regexCallNodes = NodeIndex::nodesOf($analysisUnit, FuncCall::class);
        $findings       = [];

        foreach ($regexCallNodes as $regexCallNode) {
            $functionName = $this->functionName($regexCallNode);
            if ($functionName === null || !in_array($functionName, $functionNames, true)) {
                continue;
            }

            if ($this->hasImmediateCommentAbove($sourceLines, $regexCallNode->getStartLine())) {
                continue;
            }

            if ($this->isInsideStringLabelledMatchArm($regexCallNode)) {
                continue;
            }

            if ($this->hasEnclosingFunctionDocReferencingRegex($regexCallNode, $functionName)) {
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
                remediation: sprintf('Add a one-line comment immediately above the %s() call explaining the regex intent. If this function is not a regex matcher in your codebase, remove it from `rules.docs.regex-comment.options.functionNames` in `.gruff-php.yaml`.', $functionName),
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
     * Check whether the regex call lives inside a `match (true)` arm whose key is a string literal
     * label. The string literal already acts as the human-readable explanation, so per-call comments
     * would duplicate it. Requires at least one literal-string arm condition reachable from the call.
     *
     * @return bool True when the call sits inside a string-labelled match arm.
     */
    private function isInsideStringLabelledMatchArm(FuncCall $regexCallNode): bool
    {
        $parent = $regexCallNode->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof MatchArm) {
                foreach ($parent->conds ?? [] as $condition) {
                    if ($this->containsRegexCall($condition, $regexCallNode) && $parent->body instanceof Scalar\String_) {
                        return true;
                    }
                }
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Determine whether a node subtree contains the target regex call. Used to confirm the
     * surrounding match-arm condition actually owns the call we're about to emit a finding for.
     *
     * @return bool True when the condition subtree reaches the target call.
     */
    private function containsRegexCall(Node $condition, FuncCall $regexCallNode): bool
    {
        if ($condition === $regexCallNode) {
            return true;
        }

        return (new NodeFinder())->findFirst(
            $condition,
            static fn (Node $node): bool => $node === $regexCallNode,
        ) instanceof Node;
    }

    /**
     * Check whether the enclosing function-like carries a docblock that already explains the regex
     * behaviour. Substring check against `regex`, `pattern`, `preg_`, or the specific function name
     * (e.g. `preg_match_all`) is enough; the rule's job is to nudge documentation, not police its
     * wording. `match` was previously included but proved too broad — see the FUNCTION_DOC_KEYWORDS
     * docblock for the rationale.
     *
     * @return bool True when the enclosing docblock already references the regex behaviour.
     */
    private function hasEnclosingFunctionDocReferencingRegex(FuncCall $regexCallNode, string $functionName): bool
    {
        $parent = $regexCallNode->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof ClassMethod || $parent instanceof Function_) {
                $docComment = $parent->getDocComment();
                if ($docComment === null) {
                    return false;
                }

                $docText  = strtolower($docComment->getText());
                $keywords = array_merge(self::FUNCTION_DOC_KEYWORDS, [$functionName]);
                foreach ($keywords as $keyword) {
                    if (str_contains($docText, $keyword)) {
                        return true;
                    }
                }

                return false;
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
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
