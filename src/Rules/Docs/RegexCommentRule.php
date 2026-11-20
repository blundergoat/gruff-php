<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Shared\PhysicalCommentAttachment;
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
 * above it, its nearest statement owner, or the first statement in a contiguous regex-owning sibling
 * group; string-labelled `match (true)` arms and narrow callable contracts also count. Advisory, medium confidence.
 */
final readonly class RegexCommentRule implements RuleInterface
{
    /** Full configuration path for the set of functions treated as regex matchers. */
    private const FUNCTION_NAMES_CONFIGURATION_KEY = 'rules.docs.regex-comment.options.functionNames';

    /** Stable identifier for regex-comment findings. */
    public const ID = 'docs.regex-comment';

    /**
     * Default PCRE functions requiring an explanation; configure rules.docs.regex-comment.options.functionNames to change the set.
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
     * @var list<string> - Lowercase substrings accepted in the nearest callable's PHPDoc.
     */
    private const FUNCTION_DOC_KEYWORDS = ['regex', 'pattern', 'preg_'];

    /**
     * Plain-language phrases accepted only beside a statically proven whitespace collapse.
     * Hyphenated folding and explicit collapse/normalisation phrases keep the contract
     * deterministic without treating generic words such as safe or valid as documentation.
     *
     * @var list<string> - Lowercase substrings accepted for a statically verified whitespace replacement.
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

    /** Semantic parameter order used when positional and named preg_replace arguments are resolved. */
    private const WHITESPACE_REPLACE_ARGUMENT_NAMES = ['pattern', 'replacement', 'subject'];

    /**
     * Describes the regex-comment rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, medium confidence).
     */
    public function definition(): RuleDefinition
    {

        return new RuleDefinition(
            id:                 self::ID,
            name:               'Regex comment',
            pillar:             Pillar::Documentation,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::Medium,
            defaultOptions:     ['functionNames' => self::REGEX_FUNCTIONS],
            description:        'Requires an explanatory comment immediately above configured PCRE calls, their nearest statement owner, or a contiguous sibling group of regex-owning statements. String-labelled match arms and narrow, call-specific callable contracts can provide equivalent context.',
            optionDescriptions: [
                'functionNames' => 'Function names treated as regex calls that require reviewer-facing purpose documentation.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'Multiline formatting separates a configured call from an own-line comment explaining the enclosing statement.',
                    'mitigation' => 'Keep the comment directly above the owning statement; blank-line-separated and previous-statement comments remain findings.',
                ],
                [
                    'shape' => 'One adjacent comment explains a contiguous run of statements that each perform part of the same regex check.',
                    'mitigation' => 'Keep the regex-owning statements physically contiguous. Blank lines, unrelated statements, and new comments end the shared group.',
                ],
                [
                    'shape' => 'A callable contract documents one regex operation or a statically visible whitespace-fold transformation.',
                    'mitigation' => 'Keep broad regex contracts to one configured call, or add a local purpose comment for each operation in a larger callable.',
                ],
            ],
        );
    }

    /**
     * Reports configured regex calls not covered by a local comment, labelled match arm, or callable contract.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for configured regex functions.
     *
     * @return list<Finding> - One finding per uncovered configured call; empty when no calls need findings.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition     = $this->definition();
        $functionNames  = $this->normalisedFunctionNames($ruleContext->settingsFor($definition)->stringListOption('functionNames'));
        $sourceLines    = explode("\n", str_replace(["\r\n", "\r"], "\n", $analysisUnit->source));
        $regexCallNodes = NodeIndex::nodesOf($analysisUnit, FuncCall::class);
        $findings       = [];
        $statementContexts      = [];
        $statementGroupCoverage = [];


        foreach ($regexCallNodes as $regexCallNode) {
            $functionName = $this->functionName($regexCallNode);

            if ($functionName === null || !in_array($functionName, $functionNames, true)) {
                continue;
            }


            if ($this->coverageReason(
                $sourceLines,
                $analysisUnit->source,
                $analysisUnit->statements,
                $statementContexts,
                $statementGroupCoverage,
                $regexCallNode,
                $functionName,
                $functionNames,
            ) !== null) {
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
                metadata:    array_merge(
                    ['function' => $functionName],
                    RemediationAction::Apply->metadata(self::FUNCTION_NAMES_CONFIGURATION_KEY),
                ),
            );
        }

        return $findings;
    }

    /**
     * Classifies the first deterministic documentation route covering one configured call.
     *
     * @param list<string> $sourceLines    - Whole-file source split on newlines, indexed from zero.
     * @param string       $source         - Whole-file source used to prove physical owner-comment placement.
     * @param list<Stmt>   $rootStatements - Top-level statements used when the owner has no parent node.
     * @param array<int, array{siblings: list<Stmt>, index: int}|null> $statementContexts - Cached sibling context per statement id.
     * @param array<int, bool> $statementGroupCoverage - Cached inherited group coverage per statement id.
     * @param FuncCall     $regexCallNode  - Configured call whose nearest user explanation is being resolved.
     * @param string       $functionName   - Lowercase configured function name used by callable contracts.
     * @param list<string> $functionNames  - Lowercase configured names used to bound broad callable contracts.
     *
     * @return 'immediate'|'statement_owner'|'statement_group'|'match_arm'|'function_contract'|null - First matching route, or null if uncovered.
     */
    private function coverageReason(
        array $sourceLines,
        string $source,
        array $rootStatements,
        array &$statementContexts,
        array &$statementGroupCoverage,
        FuncCall $regexCallNode,
        string $functionName,
        array $functionNames,
    ): ?string {
        // Prefer a comment at the call site before checking broader documentation.
        if ($this->hasImmediateCommentAbove($sourceLines, $regexCallNode->getStartLine())) {
            return 'immediate';
        }

        // Multiline formatting can move the call below a comment attached to its nearest statement.
        if ($this->hasAdjacentStatementOwnerComment($source, $regexCallNode)) {
            return 'statement_owner';
        }

        // Consecutive regex-owning statements can share the first statement's adjacent group comment.
        if ($this->hasContiguousStatementGroupComment(
            $source,
            $rootStatements,
            $statementContexts,
            $statementGroupCoverage,
            $regexCallNode,
            $functionNames,
        )) {
            return 'statement_group';
        }

        // A string-literal match-arm result is treated as labelled context for its condition.
        if ($this->isInsideStringLabelledMatchArm($regexCallNode)) {
            return 'match_arm';
        }

        // Check callable-level coverage last so nearer source context always wins.
        if ($this->hasCallableContractCoveringCall($regexCallNode, $functionName, $functionNames)) {
            return 'function_contract';
        }

        return null;
    }

    /**
     * Reports whether the call's nearest statement has an own-line comment directly above it.
     *
     * @param string   $source        - Whole-file source used to reject trailing same-line comments.
     * @param FuncCall $regexCallNode - Configured call whose statement owner is being checked.
     * @return bool - True only for a physically adjacent comment belonging to the nearest statement.
     */
    private function hasAdjacentStatementOwnerComment(string $source, FuncCall $regexCallNode): bool
    {
        $statementOwner = $this->nearestStatementOwner($regexCallNode);

        return $statementOwner !== null && $this->hasOwnLineAdjacentComment($source, $statementOwner);
    }

    /**
     * Reports whether a call belongs to a contiguous sibling run documented above its first regex statement.
     *
     * @param string       $source         - Whole-file source used to prove own-line comment placement.
     * @param list<Stmt>   $rootStatements - Top-level statements used for file-scope statement groups.
     * @param array<int, array{siblings: list<Stmt>, index: int}|null> $statementContexts - Cached sibling context per statement id.
     * @param array<int, bool> $statementGroupCoverage - Cached inherited coverage per statement id.
     * @param FuncCall     $regexCallNode  - Configured call whose owning statement is the end of the candidate group.
     * @param list<string> $functionNames  - Lowercase configured function names required on every grouped statement.
     * @return bool - True when the call is covered by the first contiguous regex statement's adjacent comment.
     */
    private function hasContiguousStatementGroupComment(
        string $source,
        array $rootStatements,
        array &$statementContexts,
        array &$statementGroupCoverage,
        FuncCall $regexCallNode,
        array $functionNames,
    ): bool {
        $statementOwner = $this->nearestStatementOwner($regexCallNode);
        // Calls without a statement owner cannot inherit a sibling statement's documentation.
        if ($statementOwner === null) {
            return false;
        }
        $statementId = spl_object_id($statementOwner);
        // Reuse the established answer when another configured call shares this statement.
        if (array_key_exists($statementId, $statementGroupCoverage)) {
            return $statementGroupCoverage[$statementId];
        }
        $context = $this->statementContext($statementOwner, $rootStatements, $statementContexts);
        // An unresolved statement list has no safe previous-sibling boundary.
        if ($context === null) {
            $statementGroupCoverage[$statementId] = false;

            return false;
        }
        $previousIndex = $context['index'] - 1;
        // The first statement in a list has no earlier group member from which to inherit coverage.
        if (!isset($context['siblings'][$previousIndex])) {
            $statementGroupCoverage[$statementId] = false;

            return false;
        }
        $previousStatement = $context['siblings'][$previousIndex];
        $isCovered = $statementOwner->getStartLine() === $previousStatement->getEndLine() + 1
            && $statementOwner->getComments() === []
            && $this->hasConfiguredRegexCall($previousStatement, $functionNames)
            && ($this->hasOwnLineAdjacentComment($source, $previousStatement)
                || ($statementGroupCoverage[spl_object_id($previousStatement)] ?? false));
        $statementGroupCoverage[$statementId] = $isCovered;
        return $isCovered;
    }

    /**
     * Resolves and caches the exact sibling list and index containing one statement.
     *
     * @param Stmt       $statement      - Statement whose sibling boundary is required.
     * @param list<Stmt> $rootStatements - Top-level statement list for file-scope owners.
     * @param array<int, array{siblings: list<Stmt>, index: int}|null> $statementContexts - Context cache populated for each resolved sibling.
     * @return array{siblings: list<Stmt>, index: int}|null - Exact sibling context, or null when the statement-list owner cannot be resolved.
     */
    private function statementContext(Stmt $statement, array $rootStatements, array &$statementContexts): ?array
    {
        $statementId = spl_object_id($statement);

        if (array_key_exists($statementId, $statementContexts)) {
            return $statementContexts[$statementId];
        }
        // Top-level statements use the analysis unit's root list because they have no parent node.
        foreach ($rootStatements as $rootStatement) {

            if ($rootStatement === $statement) {
                $this->cacheStatementContexts($rootStatements, $statementContexts);

                return $statementContexts[$statementId];
            }
        }

        $parent = $statement->getAttribute('parent');
        // Missing parser parent links prevent a safe sibling-boundary decision.
        if (!$parent instanceof Node) {
            return null;
        }
        // A parent can expose several arrays, so locate the exact all-statement child list.
        foreach ($parent->getSubNodeNames() as $subNodeName) {
            $subNode = $parent->{$subNodeName};
            // Scalar and single-node fields cannot contain statement siblings.
            if (!is_array($subNode)) {
                continue;
            }
            $siblings          = [];
            $containsStatement = false;
            // Mixed child arrays are not statement lists and must not form documentation groups.
            foreach ($subNode as $childNode) {

                if (!$childNode instanceof Stmt) {
                    $siblings          = [];
                    $containsStatement = false;
                    break;
                }
                $siblings[]        = $childNode;
                $containsStatement = $containsStatement || $childNode === $statement;
            }


            if ($containsStatement) {
                $this->cacheStatementContexts($siblings, $statementContexts);

                return $statementContexts[$statementId];
            }
        }
        $statementContexts[$statementId] = null;
        return null;
    }

    /**
     * Caches one statement list by object id so later calls can reuse its sibling context.
     *
     * @param list<Stmt> $statements - Exact sibling list in source order.
     * @param array<int, array{siblings: list<Stmt>, index: int}|null> $statementContexts - Context cache populated in place.
     * @return void - Populates the context cache for every sibling.
     */
    private function cacheStatementContexts(array $statements, array &$statementContexts): void
    {

        foreach ($statements as $index => $statement) {
            $statementContexts[spl_object_id($statement)] = ['siblings' => $statements, 'index' => $index];
        }
    }

    /**
     * Reports whether one statement directly owns at least one configured regex call.
     *
     * @param Stmt         $statement     - Sibling statement being considered for group membership.
     * @param list<string> $functionNames - Lowercase configured regex function names.
     * @return bool - True when a configured call resolves back to this exact nearest statement owner.
     */
    private function hasConfiguredRegexCall(Stmt $statement, array $functionNames): bool
    {
        $calls = (new NodeFinder())->findInstanceOf($statement, FuncCall::class);
        // A group member must directly own a configured call, not merely contain one in a nested scope.
        foreach ($calls as $call) {
            $functionName = $this->functionName($call);

            if ($functionName !== null
                && in_array($functionName, $functionNames, true)
                && $this->nearestStatementOwner($call) === $statement) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether one statement carries a physically adjacent own-line comment.
     *
     * @param string $source    - Whole-file source used for physical placement checks.
     * @param Stmt   $statement - Statement whose attached comments are tested.
     * @return bool - True when any attached comment is its own line immediately above the statement.
     */
    private function hasOwnLineAdjacentComment(string $source, Stmt $statement): bool
    {
        // Parser-attached comments still need a physical own-line and adjacency check.
        foreach ($statement->getComments() as $comment) {

            if (PhysicalCommentAttachment::isOwnLineImmediatelyAbove($comment, $statement, $source)) {
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

            if ($parent instanceof FunctionLike) {
                return null;
            }


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
     * @return bool - True when the previous physical line contains only a comment and whitespace.
     */
    private function hasImmediateCommentAbove(array $sourceLines, int $regexCallLine): bool
    {
        $previousLine = $sourceLines[$regexCallLine - 2] ?? null;
        if ($previousLine === null) {

            return false;
        }

        // A comment followed by executable code documents that code, not the regex statement below it.
        return PhysicalCommentAttachment::isCommentOnlyLine($previousLine);
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

            return null;
        }

        // Lowercase so the comparison against configured names is case-insensitive, matching PHP call semantics.
        return strtolower($funcCall->name->toString());
    }

    /**
     * Resolves the nearest enclosing named function or method symbol, skipping closures and arrow functions.
     *
     * @param Node $node - Regex call node whose parents are searched for a named function or method.
     *
     * @return string|null - Named ancestor's symbol, or null when no named function or method encloses the call.
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
     * Reports whether the call occurs in a match-arm condition with a string-literal result.
     * A string-literal result is treated as context for the condition, so this rule does not require a separate comment.
     *
     * @param FuncCall $regexCallNode - Regex call under inspection; its ancestor match arms are scanned for a label.
     *
     * @return bool - True when an arm condition contains the call and the arm result is a string literal.
     */
    private function isInsideStringLabelledMatchArm(FuncCall $regexCallNode): bool
    {
        $parent = $regexCallNode->getAttribute('parent');


        while ($parent instanceof Node) {
            if ($parent instanceof MatchArm) {

                foreach ($parent->conds ?? [] as $condition) {
                    if ($this->containsRegexCall($condition, $regexCallNode) && $parent->body instanceof Scalar\String_) {
                        // A string-literal result is treated as context for the condition containing this call.
                        return true;
                    }
                }
            }

            $parent = $parent->getAttribute('parent');
        }


        return false;
    }

    /**
     * Reports whether a match-arm condition subtree contains the exact target regex call.
     *
     * @param Node     $condition - Match-arm condition subtree to search for the target call.
     * @param FuncCall $regexCallNode - Exact call node being matched by identity, not by structural equality.
     *
     * @return bool - True when the condition subtree reaches the target call.
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
     * Reports whether the nearest callable docblock qualifies as context for this call under bounded syntactic checks.
     *
     * @param FuncCall     $regexCallNode - Configured call whose nearest callable docblock is examined.
     * @param string       $functionName - Lowercase call name used for explicit contract matching.
     * @param list<string> $functionNames - Configured names counted within the same callable scope.
     *
     * @return bool - True for a recognized literal whitespace-collapse shape or a one-call explicit regex contract.
     */
    private function hasCallableContractCoveringCall(
        FuncCall $regexCallNode,
        string $functionName,
        array $functionNames,
    ): bool {
        $callable = $this->nearestCallable($regexCallNode);


        if ($callable === null) {
            return false;
        }

        $docComment = $callable->getDocComment();


        if ($docComment === null) {
            return false;
        }

        $docText = strtolower($docComment->getText());

        // A narrow prose contract applies only when this call has the recognized literal collapse shape.
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
     * Recognises the exact literal preg_replace shape that this rule treats as a whitespace collapse.
     *
     * @param FuncCall $regexCallNode - Call whose arguments are checked without evaluating user code.
     * @param string   $functionName - Resolved lowercase callee name.
     *
     * @return bool - True only for the exact three-argument literal shape recognized by this rule.
     */
    private function isWhitespaceCollapseCall(FuncCall $regexCallNode, string $functionName): bool
    {
        // Other configured functions and limited replacements do not match the accepted whitespace-collapse shape.
        if ($functionName !== 'preg_replace'
            || count($regexCallNode->args) !== self::WHITESPACE_REPLACE_ARGUMENT_COUNT) {
            return false;
        }

        $arguments = $this->whitespaceReplaceArguments($regexCallNode);

        // Invalid, incomplete, or ambiguous argument mappings do not match the accepted shape.
        if ($arguments === null) {
            return false;
        }

        $pattern     = $arguments['pattern']->value;
        $replacement = $arguments['replacement']->value;

        // Literal pattern and replacement values keep this check limited to statically visible syntax.
        if (!$pattern instanceof Scalar\String_ || !$replacement instanceof Scalar\String_) {
            return false;
        }

        return $pattern->value === self::WHITESPACE_COLLAPSE_PATTERN
            && $replacement->value === ' ';
    }

    /**
     * Resolve the three required preg_replace arguments by their PHP parameter names.
     *
     * @param FuncCall $regexCallNode - Exact three-argument call whose positional and named arguments are mapped.
     *
     * @return array{pattern: Arg, replacement: Arg, subject: Arg}|null - Semantic arguments, or null for an invalid/unsupported mapping.
     */
    private function whitespaceReplaceArguments(FuncCall $regexCallNode): ?array
    {
        $resolvedArguments  = [];
        $nextPositionalSlot = 0;
        $hasNamedArgument   = false;


        foreach ($regexCallNode->args as $argument) {
            // Placeholders and unpacking prevent a definite mapping to all three parameter slots.
            if (!$argument instanceof Arg || $argument->unpack) {
                return null;
            }

            // Positional arguments fill the next slot only before any named argument appears.
            if ($argument->name === null) {
                $argumentName = self::WHITESPACE_REPLACE_ARGUMENT_NAMES[$nextPositionalSlot] ?? null;

                // A positional argument after named syntax, or beyond the known slots, is not a valid exact call.
                if ($hasNamedArgument || $argumentName === null) {
                    return null;
                }

                ++$nextPositionalSlot;
            } else {
                $hasNamedArgument = true;
                $argumentName     = $argument->name->toString();
            }

            // Unknown or duplicate parameter names cannot establish the required transformation.
            if (!in_array($argumentName, self::WHITESPACE_REPLACE_ARGUMENT_NAMES, true)
                || isset($resolvedArguments[$argumentName])) {
                return null;
            }

            $resolvedArguments[$argumentName] = $argument;
        }

        $pattern     = $resolvedArguments['pattern'] ?? null;
        $replacement = $resolvedArguments['replacement'] ?? null;
        $subject     = $resolvedArguments['subject'] ?? null;

        // Every semantic slot must resolve to a concrete argument before the caller reads literal values.
        if (!$pattern instanceof Arg || !$replacement instanceof Arg || !$subject instanceof Arg) {
            return null;
        }

        return ['pattern' => $pattern, 'replacement' => $replacement, 'subject' => $subject];
    }

    /**
     * Matches the bounded plain-language phrases accepted for a statically recognized whitespace-collapse shape.
     *
     * @param string $docText - Lowercase nearest-callable PHPDoc text.
     *
     * @return bool - True when the doc text contains an accepted folding, collapse, or normalisation phrase.
     */
    private function hasWhitespaceCollapseDescription(string $docText): bool
    {

        foreach (self::WHITESPACE_CONTRACT_PHRASES as $phrase) {
            // The first exact phrase is sufficient after the call has matched the required literal shape.
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
