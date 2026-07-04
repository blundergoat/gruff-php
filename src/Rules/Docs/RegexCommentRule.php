<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Flags a PCRE call (preg_match, preg_replace, ...) with no one-line comment explaining what the pattern
 * checks, so the user documents the intent of regexes a reviewer would otherwise have to decode by hand.
 *
 * Runs per file over the configured regex functions. A call is exempt when a comment sits immediately
 * above it, when it lives in a string-labelled `match (true)` arm (the label already names it), or when the
 * enclosing function's docblock references the regex behaviour. Advisory, medium confidence.
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
     * Describes the regex-comment rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, medium confidence).
     */
    public function definition(): RuleDefinition
    {
        // functionNames defaults to the five PCRE calls; exposed via defaultOptions so projects can retarget it.
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
     * Reports each configured regex matcher call that lacks a preceding explanatory comment.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for configured regex functions.
     *
     * @return list<Finding> - Findings for uncommented regex calls.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition     = $this->definition();
        $functionNames  = $this->normalisedFunctionNames($ruleContext->settingsFor($definition)->stringListOption('functionNames'));
        $sourceLines    = explode("\n", str_replace(["\r\n", "\r"], "\n", $analysisUnit->source));
        $regexCallNodes = NodeIndex::nodesOf($analysisUnit, FuncCall::class);
        $findings       = [];

        // Weigh each function call in the file.
        foreach ($regexCallNodes as $regexCallNode) {
            $functionName = $this->functionName($regexCallNode);
            // Only the configured regex functions are in scope.
            if ($functionName === null || !in_array($functionName, $functionNames, true)) {
                continue;
            }

            // A comment right above the call already explains it.
            if ($this->hasImmediateCommentAbove($sourceLines, $regexCallNode->getStartLine())) {
                continue;
            }

            // A string-labelled match arm already names what the regex does.
            if ($this->isInsideStringLabelledMatchArm($regexCallNode)) {
                continue;
            }

            // A function docblock that describes the regex covers the call.
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
     * Reports whether the physical line immediately above a regex call is a comment.
     *
     * @param list<string> $sourceLines - Whole-file source split on newlines, indexed from zero.
     * @param int          $regexCallLine - 1-based line of the regex call whose preceding line is checked.
     *
     * @return bool - True when the previous physical line is a comment.
     */
    private function hasImmediateCommentAbove(array $sourceLines, int $regexCallLine): bool
    {
        $previousLine = $sourceLines[$regexCallLine - 2] ?? null;
        if ($previousLine === null) {
            // Call sits on the first line, so there is no line above it to carry an explanation.
            return false;
        }

        $trimmedLine = ltrim($previousLine);

        // A leading // # or single-line /* */ on the prior line counts as the required inline explanation.
        return str_starts_with($trimmedLine, '//')
            || str_starts_with($trimmedLine, '#')
            || (str_starts_with($trimmedLine, '/*') && str_contains($trimmedLine, '*/'));
    }

    /**
     * Resolves the lowercase callee name of a call, or null for a dynamic call.
     *
     * @param FuncCall $funcCall - Call node whose callee name is being resolved for the configured-function check.
     *
     * @return string|null - Lowercase function name, or null for dynamic calls.
     */
    private function functionName(FuncCall $funcCall): ?string
    {
        if (!$funcCall->name instanceof Name) {
            // A variable or expression callee has no static name to compare against the configured list.
            return null;
        }

        // Lowercase so the comparison against configured names is case-insensitive, matching PHP call semantics.
        return strtolower($funcCall->name->toString());
    }

    /**
     * Resolves the function or method symbol that encloses a regex call, or null at file scope.
     *
     * @param Node $node - Regex call node; the walk starts here and climbs parents to find its enclosing callable.
     *
     * @return string|null - Function or method symbol containing the regex call.
     */
    private function symbol(Node $node): ?string
    {
        $parent = $node->getAttribute('parent');

        // Climb the parent chain to the enclosing callable.
        while ($parent instanceof Node) {
            if ($parent instanceof ClassMethod || $parent instanceof Function_) {
                // First enclosing callable found; its resolved symbol names the finding's location for the reader.
                return CyclomaticComplexityRule::resolveSymbol($parent);
            }

            $parent = $parent->getAttribute('parent');
        }

        // Call lives at file scope with no enclosing callable, so there is no symbol to attribute it to.
        return null;
    }

    /**
     * Reports whether the regex call lives inside a `match (true)` arm whose key is a string-literal label.
     *
     * The string literal already acts as the human-readable explanation, so per-call comments would
     * duplicate it. Requires at least one literal-string arm condition reachable from the call.
     *
     * @param FuncCall $regexCallNode - Regex call under inspection; its ancestor match arms are scanned for a label.
     *
     * @return bool - True when the call sits inside a string-labelled match arm.
     */
    private function isInsideStringLabelledMatchArm(FuncCall $regexCallNode): bool
    {
        $parent = $regexCallNode->getAttribute('parent');

        // Climb the parent chain looking for an owning match arm.
        while ($parent instanceof Node) {
            if ($parent instanceof MatchArm) {
                // Check each condition of the arm.
                foreach ($parent->conds ?? [] as $condition) {
                    if ($this->containsRegexCall($condition, $regexCallNode) && $parent->body instanceof Scalar\String_) {
                        // The arm owning this call yields a string-literal label, which already names the regex.
                        return true;
                    }
                }
            }

            $parent = $parent->getAttribute('parent');
        }

        // No ancestor arm both owns the call and yields a string label, so the exemption does not apply.
        return false;
    }

    /**
     * Reports whether a node subtree contains the exact target regex call.
     *
     * Used to confirm the surrounding match-arm condition actually owns the call we're about to emit a
     * finding for.
     *
     * @param Node     $condition - Match-arm condition subtree to search for the target call.
     * @param FuncCall $regexCallNode - Exact call node being matched by identity, not by structural equality.
     *
     * @return bool - True when the condition subtree reaches the target call.
     */
    private function containsRegexCall(Node $condition, FuncCall $regexCallNode): bool
    {
        if ($condition === $regexCallNode) {
            // The condition is the call itself, so no subtree walk is needed to confirm ownership.
            return true;
        }

        // Otherwise the condition owns the call only if the exact same node instance appears somewhere below it.
        return (new NodeFinder())->findFirst(
            $condition,
            static fn (Node $node): bool => $node === $regexCallNode,
        ) instanceof Node;
    }

    /**
     * Reports whether the enclosing callable's docblock already references the regex behaviour.
     *
     * Substring check against `regex`, `pattern`, `preg_`, or the specific function name (e.g.
     * `preg_match_all`) is enough; the rule's job is to nudge documentation, not police its wording.
     * `match` was previously included but proved too broad - see the FUNCTION_DOC_KEYWORDS docblock.
     *
     * @param FuncCall $regexCallNode - Regex call whose nearest enclosing callable docblock is examined.
     * @param string   $functionName - Lowercased call name, added as a keyword so a doc naming it counts as coverage.
     *
     * @return bool - True when the enclosing docblock already references the regex behaviour.
     */
    private function hasEnclosingFunctionDocReferencingRegex(FuncCall $regexCallNode, string $functionName): bool
    {
        $parent = $regexCallNode->getAttribute('parent');

        // Climb the parent chain to the nearest callable.
        while ($parent instanceof Node) {
            if ($parent instanceof ClassMethod || $parent instanceof Function_) {
                $docComment = $parent->getDocComment();
                if ($docComment === null) {
                    // The nearest callable has no docblock at all, so it cannot satisfy the exemption.
                    return false;
                }

                $docText  = strtolower($docComment->getText());
                $keywords = array_merge(self::FUNCTION_DOC_KEYWORDS, [$functionName]);
                // Any regex keyword or the call name counts as coverage.
                foreach ($keywords as $keyword) {
                    if (str_contains($docText, $keyword)) {
                        // Docblock mentions a regex keyword or the call name, so the per-call comment is redundant.
                        return true;
                    }
                }

                // Nearest callable is documented but never mentions the regex, so the exemption does not apply.
                return false;
            }

            $parent = $parent->getAttribute('parent');
        }

        // Call has no enclosing callable to carry a covering docblock, so it must rely on an inline comment.
        return false;
    }

    /**
     * Lowercases and de-duplicates the configured regex function names.
     *
     * @param list<string> $functionNames - Configured regex function names.
     *
     * @return list<string> - Lowercase regex function names.
     */
    private function normalisedFunctionNames(array $functionNames): array
    {
        // Lowercase and de-duplicate so config order and casing never change which calls the rule matches.
        return array_values(array_unique(array_map(
            static fn (string $functionName): string => strtolower($functionName),
            $functionNames,
        )));
    }
}
