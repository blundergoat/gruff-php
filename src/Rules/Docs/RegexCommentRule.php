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
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Flags a PCRE call (preg_match, preg_replace, ...) with no one-line comment explaining what the pattern
 * checks, so the user documents the intent of regexes a reviewer would otherwise have to decode by hand.
 *
 * Runs per file over the configured regex functions. A call is exempt when a comment sits immediately
 * above it or its nearest statement owner, when a string-labelled `match (true)` arm names it, or when
 * the enclosing callable carries a narrowly applicable regex contract. Advisory, medium confidence.
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
     * Explicit regex words that can cover the sole configured call in a callable.
     * The one-call ceiling prevents a broad function summary from hiding several
     * unrelated transformations that each need their own reviewer-facing purpose.
     *
     * @var list<string>
     */
    private const FUNCTION_DOC_KEYWORDS = ['regex', 'pattern', 'preg_'];

    /**
     * Plain-language phrases accepted only beside a statically proven whitespace collapse.
     * Hyphenated folding and explicit collapse/normalisation phrases keep the contract
     * deterministic without treating generic words such as safe or valid as documentation.
     *
     * @var list<string>
     */
    private const WHITESPACE_CONTRACT_PHRASES = [
        'whitespace-fold',
        'collapse whitespace',
        'collapsed whitespace',
        'whitespace collapse',
        'normalise whitespace',
        'normalised whitespace',
        'whitespace normalisation',
        'normalize whitespace',
        'normalized whitespace',
        'whitespace normalization',
    ];

    /** Exact static pattern accepted as a complete whitespace-collapse transformation. */
    private const WHITESPACE_COLLAPSE_PATTERN = '/\s+/';

    /** A complete preg_replace transformation has pattern, replacement, and subject arguments. */
    private const WHITESPACE_REPLACE_ARGUMENT_COUNT = 3;

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
            description:     'Requires an explanatory comment immediately above configured PCRE calls or their nearest statement owner. String-labelled match arms and narrow, call-specific callable contracts can provide equivalent context.',
            optionDescriptions: [
                'functionNames' => 'Function names treated as regex calls that require reviewer-facing purpose documentation.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'Multiline formatting separates a configured call from an own-line comment explaining the enclosing statement.',
                    'mitigation' => 'Keep the comment directly above the owning statement; blank-line-separated and previous-statement comments remain findings.',
                ],
                [
                    'shape' => 'A callable contract documents one regex operation or a statically visible whitespace-fold transformation.',
                    'mitigation' => 'Keep broad regex contracts to one configured call, or add a local purpose comment for each operation in a larger callable.',
                ],
            ],
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

            // Documented calls produce no finding, whichever ordered coverage route explains them.
            if ($this->coverageReason($sourceLines, $regexCallNode, $functionName, $functionNames) !== null) {
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
     * Classifies the first deterministic documentation route covering one configured call.
     *
     * @param list<string> $sourceLines - Whole-file source split on newlines, indexed from zero.
     * @param FuncCall     $regexCallNode - Configured call whose nearest user explanation is being resolved.
     * @param string       $functionName - Lowercase configured function name used by callable contracts.
     * @param list<string> $functionNames - Lowercase configured names used to bound broad callable contracts.
     *
     * @return 'immediate'|'statement_owner'|'match_arm'|'function_contract'|null - First matching route, or null when the user still needs a finding.
     */
    private function coverageReason(
        array $sourceLines,
        FuncCall $regexCallNode,
        string $functionName,
        array $functionNames,
    ): ?string {
        // A physical comment immediately above the call is the narrowest and oldest coverage route.
        if ($this->hasImmediateCommentAbove($sourceLines, $regexCallNode->getStartLine())) {
            return 'immediate';
        }

        // Multiline formatting can move the call below a comment attached to its nearest statement.
        if ($this->hasAdjacentStatementOwnerComment($sourceLines, $regexCallNode)) {
            return 'statement_owner';
        }

        // A string-labelled match arm names the condition without a duplicate inline comment.
        if ($this->isInsideStringLabelledMatchArm($regexCallNode)) {
            return 'match_arm';
        }

        // The existing callable contract is checked last so nearer source context always wins.
        if ($this->hasCallableContractCoveringCall($regexCallNode, $functionName, $functionNames)) {
            return 'function_contract';
        }

        return null;
    }

    /**
     * Reports whether the call's nearest statement has an own-line comment directly above it.
     *
     * @param list<string> $sourceLines - Whole-file source used to reject trailing same-line comments.
     * @param FuncCall     $regexCallNode - Configured call whose statement owner is being checked.
     *
     * @return bool - True only for a physically adjacent comment belonging to the nearest statement.
     */
    private function hasAdjacentStatementOwnerComment(array $sourceLines, FuncCall $regexCallNode): bool
    {
        $statementOwner = $this->nearestStatementOwner($regexCallNode);

        // Calls outside a statement or inside a nearer nested callable have no eligible owner comment.
        if ($statementOwner === null) {
            return false;
        }

        // PHP-Parser may attach several comments, so inspect each while enforcing physical adjacency ourselves.
        foreach ($statementOwner->getComments() as $comment) {
            // Only the comment ending immediately above the owner can explain this statement.
            if ($comment->getEndLine() !== $statementOwner->getStartLine() - 1) {
                continue;
            }

            $commentLine = $sourceLines[$comment->getStartLine() - 1] ?? null;

            // A missing source line cannot prove that the token began on its own physical line.
            if ($commentLine === null) {
                continue;
            }

            $trimmedLine = ltrim($commentLine);

            // Leading comment syntax proves only indentation appeared before the token, never prior code.
            if (str_starts_with($trimmedLine, '//')
                || str_starts_with($trimmedLine, '#')
                || str_starts_with($trimmedLine, '/*')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the first statement owning a call without crossing a nested callable contract.
     *
     * @param FuncCall $regexCallNode - Configured call whose formatting owner is required.
     *
     * @return Stmt|null - Nearest statement, or null when a callable boundary appears first.
     */
    private function nearestStatementOwner(FuncCall $regexCallNode): ?Stmt
    {
        $parent = $regexCallNode->getAttribute('parent');

        // Climb only within the call's own callable so outer statements cannot lend their comments inward.
        while ($parent instanceof Node) {
            // A closure, arrow function, method, or function boundary ends ownership for this call.
            if ($parent instanceof FunctionLike) {
                return null;
            }

            // The first statement is the narrowest formatting owner visible to the user.
            if ($parent instanceof Stmt) {
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
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
     * Reports whether the nearest callable carries a deterministic contract for this exact call.
     *
     * @param FuncCall     $regexCallNode - Configured call whose nearest callable docblock is examined.
     * @param string       $functionName - Lowercase call name used for explicit contract matching.
     * @param list<string> $functionNames - Configured names counted within the same callable scope.
     *
     * @return bool - True for a proven whitespace fold or a one-call explicit regex contract.
     */
    private function hasCallableContractCoveringCall(
        FuncCall $regexCallNode,
        string $functionName,
        array $functionNames,
    ): bool {
        $callable = $this->nearestCallable($regexCallNode);

        // File-scope calls have no callable contract to explain their transformation.
        if ($callable === null) {
            return false;
        }

        $docComment = $callable->getDocComment();

        // An undocumented callable cannot replace the required call-level explanation.
        if ($docComment === null) {
            return false;
        }

        $docText = strtolower($docComment->getText());

        // A narrow prose contract applies only when this call visibly performs the promised fold.
        if ($this->isWhitespaceCollapseCall($regexCallNode, $functionName)
            && $this->hasWhitespaceCollapseDescription($docText)) {
            return true;
        }

        // Broad regex terms cover one configured call at most; larger callables need local explanations.
        if ($this->configuredRegexCallCount($callable, $functionNames) !== 1) {
            return false;
        }

        $keywords = array_merge(self::FUNCTION_DOC_KEYWORDS, [$functionName]);

        // The sole call is covered when the callable explicitly names regex behaviour or that function.
        foreach ($keywords as $keyword) {
            // Generic prose without one of these explicit terms remains a finding for the user.
            if (str_contains($docText, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the nearest method, function, closure, or arrow function containing a call.
     *
     * @param FuncCall $regexCallNode - Configured call whose callable boundary is required.
     *
     * @return FunctionLike|null - Nearest callable, or null when the call is at file scope.
     */
    private function nearestCallable(FuncCall $regexCallNode): ?FunctionLike
    {
        $parent = $regexCallNode->getAttribute('parent');

        // Stop at the first callable so an outer method cannot document a nested callback's call.
        while ($parent instanceof Node) {
            // This is the contract boundary visible to the call under inspection.
            if ($parent instanceof FunctionLike) {
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * Counts configured calls owned directly by one callable, excluding nested callable bodies.
     *
     * @param FunctionLike $callable - Callable whose explicit broad contract is being bounded.
     * @param list<string> $functionNames - Lowercase configured names included in the count.
     *
     * @return int - Number of directly owned configured calls; zero means the contract covers none.
     */
    private function configuredRegexCallCount(FunctionLike $callable, array $functionNames): int
    {
        $callCount = 0;
        $calls     = (new NodeFinder())->findInstanceOf($callable, FuncCall::class);

        // Count only statically named configured calls whose nearest callable is this exact node.
        foreach ($calls as $call) {
            $candidateName = $this->functionName($call);

            // Dynamic, unconfigured, and nested-callable calls cannot widen this contract's reach.
            if ($candidateName === null
                || !in_array($candidateName, $functionNames, true)
                || $this->nearestCallable($call) !== $callable) {
                continue;
            }

            ++$callCount;
        }

        return $callCount;
    }

    /**
     * Proves that a call replaces every static whitespace run with one literal space.
     *
     * @param FuncCall $regexCallNode - Call whose arguments are checked without evaluating user code.
     * @param string   $functionName - Resolved lowercase callee name.
     *
     * @return bool - True only for the exact three-argument static collapse transformation.
     */
    private function isWhitespaceCollapseCall(FuncCall $regexCallNode, string $functionName): bool
    {
        // Other configured functions and limited replacements do not prove a complete fold.
        if ($functionName !== 'preg_replace'
            || count($regexCallNode->args) !== self::WHITESPACE_REPLACE_ARGUMENT_COUNT) {
            return false;
        }

        $patternArgument     = $regexCallNode->args[0];
        $replacementArgument = $regexCallNode->args[1];

        // First and second slots must be real arguments, never first-class-callable placeholders.
        if (!$patternArgument instanceof Arg || !$replacementArgument instanceof Arg) {
            return false;
        }

        $pattern     = $patternArgument->value;
        $replacement = $replacementArgument->value;

        // Literal pattern and replacement values are required so no runtime guess enters suppression.
        if (!$pattern instanceof Scalar\String_ || !$replacement instanceof Scalar\String_) {
            return false;
        }

        return $pattern->value === self::WHITESPACE_COLLAPSE_PATTERN
            && $replacement->value === ' ';
    }

    /**
     * Matches the bounded plain-language phrases accepted for a proven whitespace fold.
     *
     * @param string $docText - Lowercase nearest-callable PHPDoc text.
     *
     * @return bool - True when the contract explicitly names folding, collapse, or normalisation.
     */
    private function hasWhitespaceCollapseDescription(string $docText): bool
    {
        // Each phrase is intentionally explicit enough to avoid generic safe or valid prose.
        foreach (self::WHITESPACE_CONTRACT_PHRASES as $phrase) {
            // The first exact phrase is sufficient because the call shape was already proven.
            if (str_contains($docText, $phrase)) {
                return true;
            }
        }

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
