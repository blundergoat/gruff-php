<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Reports an empty catch block, where a failure disappears without a log line, a rethrow or a fallback.
 *
 * A user who wrapped a payment call in `try { ... } catch (Exception $e) { // do nothing }` gets one warning on the catch.
 * A body of only comments counts as empty.
 *
 * Two silent catches stay quiet, because the silence is reviewed or the failure is handled later:
 * - its comment gives a reason, such as "Destructors must not throw.";
 * - the code after the try reads the caught exception, such as `if ($error !== null) { log($error); }`.
 */
final class SilentCatchRule implements RuleInterface
{
    /**
     * Stable rule identifier for silent catch findings.
     */
    public const ID = 'security.silent-catch';

    /**
     * Words that turn a comment into a reason for the silence, such as "if" in "Do nothing if we cannot memzero".
     *
     * @var list<string> - Tokens used to recognise explanatory catch comments.
     */
    private const REASON_WORDS = ['if', 'because', 'since', 'when', 'unless', 'must', 'cannot', 'cant', 'below', 'expected', 'otherwise', 'continue', 'from'];

    /**
     * Stock phrasing of a silent catch, such as "do nothing" or "ignore the exception", which restates the silence without saying why.
     *
     * @var list<string> - Tokens excluded when looking for wording beyond stock silence phrases.
     */
    private const STOCK_PHRASE_WORDS = [
        'do', 'does', 'nothing', 'no', 'op', 'noop', 'nop', 'ignore', 'ignored', 'ignoring', 'silent', 'silently', 'silence',
        'swallow', 'swallowed', 'catch', 'caught', 'exception', 'exceptions', 'error', 'errors', 'intentionally',
        'deliberately', 'here', 'just', 'pass', 'the', 'it', 'this',
    ];

    /**
     * Describes the rule for list-rules output and each reported finding.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Silent catch block',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
            // Disabled by default because its post-repair precision remained below the required floor (ADR-034).

            isEnabledByDefault: false,
        );
    }

    /**
     * Reports each otherwise empty catch with neither recognised reason wording nor a recognised later read.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per catch that matches the silence heuristic; empty when none match.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Catch_::class) as $catch) {

            if (!$this->hasNoHandling($catch)) {
                continue;
            }


            if ($this->commentExplainsSilence($catch) || $this->readsCaughtExceptionLater($catch, $analysisUnit)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Catch block swallows exceptions without handling or reporting them.',
                filePath:    $analysisUnit->file->displayPath,
                line:        $catch->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                remediation: 'Log, rethrow, convert, or otherwise handle caught exceptions explicitly.',
            );
        }

        return $findings;
    }

    /**
     * Reports whether a catch block contains no executable statements.
     *
     * @param Stmt\Catch_ $catch - Catch clause to inspect.
     *
     * @return bool - True when every body entry is a non-executable placeholder; false when any executable statement appears.
     */
    private function hasNoHandling(Stmt\Catch_ $catch): bool
    {
        // Comment-only bodies use Nop placeholders; any other statement excludes the catch from this rule.
        foreach ($catch->stmts as $statement) {

            if (!$statement instanceof Stmt\Nop) {
                return false;
            }
        }

        return true;
    }

    /**
     * Matches comments inside an otherwise empty catch against the explanation heuristic.
     * A reason word or phrase also needs a distinct explanatory word.
     * TODO-style markers reject the prose; blank and selected code-like lines are filtered before matching.
     *
     * @param Stmt\Catch_ $catch - Empty catch whose placeholder statements carry the comments.
     *
     * @return bool - True when comment text matches the heuristic; false for no comment, stock phrasing, or an open TODO.
     */
    private function commentExplainsSilence(Stmt\Catch_ $catch): bool
    {
        $proseLines = [];

        foreach ($catch->stmts as $placeholder) {
            // Collect every attached comment because one catch body may split its explanation across several comments.
            foreach ($placeholder->getComments() as $comment) {
                // Process block-comment lines separately so disabled code does not hide explanatory prose on another line.
                foreach (preg_split('/\R/', $comment->getText()) ?: [] as $commentLine) {
                    // Ignore comment syntax so only the catch's explanatory words influence the heuristic.
                    $text = trim((string) preg_replace('~^\s*(?://+|#+|/\*+|\*+/?)|\*+/\s*$~', '', $commentLine));
                    // Ignore blank lines and disabled code ending in a semicolon or brace because they do not explain the silence.
                    if ($text !== '' && preg_match('/[;{}]$|^\$\w/', $text) !== 1) {
                        $proseLines[] = $text;
                    }
                }
            }
        }

        $prose = implode(' ', $proseLines);
        // No prose or an open TODO, FIXME, XXX or HACK marker prevents a heuristic match.
        if ($prose === '' || preg_match('/\b(?:TODO|FIXME|XXX|HACK)\b/i', $prose) === 1) {
            return false;
        }

        // Apostrophes are dropped first so "can't" reads as the reason word "cant", not "can" and "t".
        $words   = preg_split('/[^a-z]+/', strtolower(str_replace(["'", '’'], '', $prose)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $phrased = ' ' . implode(' ', $words) . ' ';
        $givesReason = array_intersect($words, self::REASON_WORDS) !== []
            || str_contains($phrased, ' fall back ') || str_contains($phrased, ' in case ');

        // A reason word alone, as in "ignore if", does not qualify; another word must remain after stock terms are removed.
        return $givesReason && array_diff($words, self::STOCK_PHRASE_WORDS, self::REASON_WORDS, ['fall', 'back', 'in', 'case']) !== [];
    }

    /**
     * Reports whether the first later use in the same block qualifies as a read of the caught exception.
     *
     * The rule treats a read such as log($error) as later handling; direct rebinding, unset, or isset of the variable does not qualify.
     *
     * @param Stmt\Catch_  $catch - Empty catch that names its exception variable.
     * @param AnalysisUnit $analysisUnit - Parsed file whose top-level statements follow a try written at file scope.
     *
     * @return bool - True when the first later use is a read; false when the catch names no variable or nothing reads it.
     */
    private function readsCaughtExceptionLater(Stmt\Catch_ $catch, AnalysisUnit $analysisUnit): bool
    {
        $tryStatement = $catch->getAttribute('parent');
        // A catch clause without a variable leaves nothing to read later.
        if (!$catch->var instanceof Expr\Variable || !is_string($catch->var->name) || !$tryStatement instanceof Stmt\TryCatch) {
            return false;
        }

        // This heuristic inspects only statements after the try in the same block.
        foreach ($this->statementsAfterTry($tryStatement, $analysisUnit) as $laterStatement) {
            $firstUse = $this->firstUseOf($laterStatement, $catch->var->name);
            // The first statement that mentions the variable decides; earlier statements that ignore it do not.
            if ($firstUse !== null) {
                return $firstUse;
            }
        }

        return false;
    }

    /**
     * Returns the statements that follow a try in the block that holds it, such as the rest of a function body.
     *
     * @param Stmt\TryCatch $tryStatement - Try statement to locate.
     * @param AnalysisUnit  $analysisUnit - Parsed file whose root statements hold a try written at file scope.
     *
     * @return list<Node> - Following sibling nodes in source order; empty when none remain or the containing block is not found.
     */
    private function statementsAfterTry(Stmt\TryCatch $tryStatement, AnalysisUnit $analysisUnit): array
    {
        $holder          = $tryStatement->getAttribute('parent');
        $statementBlocks = [$analysisUnit->statements];
        // A try inside a function, branch or loop sits in one of that node's statement lists rather than at file scope.
        if ($holder instanceof Node) {
            $statementBlocks = array_filter(array_map(static fn(string $name): mixed => $holder->{$name}, $holder->getSubNodeNames()), 'is_array');
        }


        foreach ($statementBlocks as $statementBlock) {
            $position = array_search($tryStatement, $statementBlock, true);

            if (is_int($position)) {
                return array_values(array_filter(array_slice($statementBlock, $position + 1), static fn(mixed $node): bool => $node instanceof Node));
            }
        }

        return [];
    }

    /**
     * Classifies the first relevant use of a variable inside a statement.
     *
     * @param Node   $node - Statement or expression to walk.
     * @param string $variableName - Caught variable's name, without the dollar sign.
     *
     * @return bool|null - True for a read, false for a rebinding, drop, or non-handling test, and null when the variable is absent.
     */
    private function firstUseOf(Node $node, string $variableName): ?bool
    {
        // A function, class or method declared here has its own variables, even when one shares the name.
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod) {
            return null;
        }

        // A closure sees the caught exception only through its captured-variable list, which reads it.
        if ($node instanceof Expr\Closure) {
            foreach ($node->uses as $closureUse) {
                if ($closureUse->var->name === $variableName) {
                    return true;
                }
            }

            return null;
        }

        // A variable occurrence not claimed by a surrounding binding form is treated as a read.
        if ($node instanceof Expr\Variable) {
            return $node->name === $variableName ? true : null;
        }

        $bindingOutcome = $this->useInBindingForm($node, $variableName);
        // An assignment, catch, unset, isset or foreach already decided whether the value was read or dropped.
        if ($bindingOutcome !== 0) {
            return $bindingOutcome === 1;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            foreach (is_array($node->{$subNodeName}) ? $node->{$subNodeName} : [$node->{$subNodeName}] as $child) {
                $firstUse = $child instanceof Node ? $this->firstUseOf($child, $variableName) : null;
                // The earliest child that mentions the variable decides for the whole statement.
                if ($firstUse !== null) {
                    return $firstUse;
                }
            }
        }

        return null;
    }

    /**
     * Classifies variable uses in forms that can bind, drop, or only test a value.
     *
     * @param Node   $node - Statement or expression to inspect.
     * @param string $variableName - Caught variable's name, without the dollar sign.
     *
     * @return int - 1 for a read, -1 for direct rebinding, unset, or isset of the variable, and 0 when child nodes decide.
     */
    private function useInBindingForm(Node $node, string $variableName): int
    {
        $isCaughtName = static fn(mixed $target): bool => $target instanceof Expr\Variable && $target->name === $variableName;

        // A read in the assigned expression handles the exception before rebinding can drop it.
        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef) {
            $rightSideUse = $this->firstUseOf($node->expr, $variableName);
            if ($rightSideUse !== null) {
                return $rightSideUse ? 1 : -1;
            }

            return $isCaughtName($node->var) ? -1 : 0;
        }

        // A direct catch binding, unset, or isset of the caught name does not count as later handling.
        if ($node instanceof Stmt\Catch_ || $node instanceof Stmt\Unset_ || $node instanceof Expr\Isset_) {
            $targets = $node instanceof Stmt\Catch_ ? [$node->var] : $node->vars;

            return array_filter($targets, $isCaughtName) !== [] ? -1 : 0;
        }

        // A foreach target can rebind the caught name, while the iterable expression may read it first.
        if ($node instanceof Stmt\Foreach_ && ($isCaughtName($node->keyVar) || $isCaughtName($node->valueVar))) {
            return $this->firstUseOf($node->expr, $variableName) === true ? 1 : -1;
        }

        return 0;
    }
}
