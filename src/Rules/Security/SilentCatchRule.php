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
     * @var list<string>
     */
    private const REASON_WORDS = ['if', 'because', 'since', 'when', 'unless', 'must', 'cannot', 'cant', 'below', 'expected', 'otherwise', 'continue', 'from'];

    /**
     * Stock phrasing of a silent catch, such as "do nothing" or "ignore the exception", which restates the silence without saying why.
     *
     * @var list<string>
     */
    private const STOCK_PHRASE_WORDS = [
        'do', 'does', 'nothing', 'no', 'op', 'noop', 'nop', 'ignore', 'ignored', 'ignoring', 'silent', 'silently', 'silence',
        'swallow', 'swallowed', 'catch', 'caught', 'exception', 'exceptions', 'error', 'errors', 'intentionally',
        'deliberately', 'here', 'just', 'pass', 'the', 'it', 'this',
    ];

    /**
     * Describes the rule for `list-rules` and for each finding it reports.
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
            // Off unless a project sets `enabled: true`: precision-floor M02 found it right on 13 of 25 judged findings after its repair, below its 0.80 floor,
            // so it is off by default rather than deleted (ADR-034).
            isEnabledByDefault: false,
        );
    }

    /**
     * Reports each catch block that drops its exception without a reason or later handling.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per unexplained empty catch; empty when every catch handles, explains or later reads its exception.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Every catch clause in the file is a candidate, including each clause of a multi-catch try.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Catch_::class) as $catch) {
            // A catch that logs, rethrows or falls back does something with the failure.
            if (!$this->hasNoHandling($catch)) {
                continue;
            }

            // A reason in the comment, or a later read of the caught value, means the silence was reviewed or the failure is handled.
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
     * Reports whether a catch block runs no code, holding at most comments.
     *
     * @param Stmt\Catch_ $catch - Catch clause to inspect.
     *
     * @return bool - True when the body is empty or holds only comments; false once it has one real statement.
     */
    private function hasNoHandling(Stmt\Catch_ $catch): bool
    {
        // A comment-only body parses as placeholder statements, so anything else is real handling.
        foreach ($catch->stmts as $statement) {
            // One real statement, such as a log call or `return null;`, means the failure is acted on.
            if (!$statement instanceof Stmt\Nop) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reports whether an empty catch's comment gives a reason for the silence, such as "Fall back to case folding below."
     *
     * A reason needs a connective word plus a word of its own, so "do nothing" and "Catch exceptions and remain silent." still report.
     * A TODO-style marker means the handling is still owed, and a commented-out line of code is not prose.
     *
     * @param Stmt\Catch_ $catch - Empty catch whose placeholder statements carry the comments.
     *
     * @return bool - True when the comment explains the silence; false for no comment, stock phrasing or an open TODO.
     */
    private function commentExplainsSilence(Stmt\Catch_ $catch): bool
    {
        $proseLines = [];
        // Each placeholder statement carries the comments written inside the catch body.
        foreach ($catch->stmts as $placeholder) {
            // A body can hold several comments, such as two `//` lines.
            foreach ($placeholder->getComments() as $comment) {
                // A block comment spans several lines, and each is judged on its own.
                foreach (preg_split('/\R/', $comment->getText()) ?: [] as $commentLine) {
                    // Strip the comment markers (`//`, `#`, `/*`, `*`, `*/`) to leave the words.
                    $text = trim((string) preg_replace('~^\s*(?://+|#+|/\*+|\*+/?)|\*+/\s*$~', '', $commentLine));
                    // A blank line, or disabled code such as `// error_log($e);` ending in `;`, `{` or `}`, says nothing about why.
                    if ($text !== '' && preg_match('/[;{}]$|^\$\w/', $text) !== 1) {
                        $proseLines[] = $text;
                    }
                }
            }
        }

        $prose = implode(' ', $proseLines);
        // No prose, or a TODO, FIXME, XXX or HACK marker saying the handling is still owed, leaves the catch unexplained.
        if ($prose === '' || preg_match('/\b(?:TODO|FIXME|XXX|HACK)\b/i', $prose) === 1) {
            return false;
        }

        // Apostrophes are dropped first so "can't" reads as the reason word "cant", not "can" and "t".
        $words   = preg_split('/[^a-z]+/', strtolower(str_replace(["'", '’'], '', $prose)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $phrased = ' ' . implode(' ', $words) . ' ';
        $givesReason = array_intersect($words, self::REASON_WORDS) !== []
            || str_contains($phrased, ' fall back ') || str_contains($phrased, ' in case ');

        // A reason word alone, as in "ignore if", explains nothing; it needs at least one word beyond the stock phrasing.
        return $givesReason && array_diff($words, self::STOCK_PHRASE_WORDS, self::REASON_WORDS, ['fall', 'back', 'in', 'case']) !== [];
    }

    /**
     * Reports whether the code after the try reads the caught exception before anything replaces or drops it.
     *
     * The first later use decides: a read, such as `log($error)`, handles the failure; a rebinding, `unset()` or `isset()` does not.
     *
     * @param Stmt\Catch_  $catch - Empty catch that names its exception variable.
     * @param AnalysisUnit $analysisUnit - Parsed file whose top-level statements follow a try written at file scope.
     *
     * @return bool - True when the first later use is a read; false when the catch names no variable or nothing reads it.
     */
    private function readsCaughtExceptionLater(Stmt\Catch_ $catch, AnalysisUnit $analysisUnit): bool
    {
        $tryStatement = $catch->getAttribute('parent');
        // `catch (Exception)` without a variable leaves nothing to read later.
        if (!$catch->var instanceof Expr\Variable || !is_string($catch->var->name) || !$tryStatement instanceof Stmt\TryCatch) {
            return false;
        }

        // Only statements after the try, in the same block, can act on the caught value.
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
     * @return list<Node> - Later statements in source order; empty when the try is the last statement in its block.
     */
    private function statementsAfterTry(Stmt\TryCatch $tryStatement, AnalysisUnit $analysisUnit): array
    {
        $holder          = $tryStatement->getAttribute('parent');
        $statementBlocks = [$analysisUnit->statements];
        // A try inside a function, branch or loop sits in one of that node's statement lists rather than at file scope.
        if ($holder instanceof Node) {
            $statementBlocks = array_filter(array_map(static fn(string $name): mixed => $holder->{$name}, $holder->getSubNodeNames()), 'is_array');
        }

        // Find the block that holds the try, then keep what comes after it.
        foreach ($statementBlocks as $statementBlock) {
            $position = array_search($tryStatement, $statementBlock, true);
            // This block holds the try, so its later entries are the statements that run next.
            if (is_int($position)) {
                return array_values(array_filter(array_slice($statementBlock, $position + 1), static fn(mixed $node): bool => $node instanceof Node));
            }
        }

        return [];
    }

    /**
     * Finds the first use of a variable inside a statement, in source order.
     *
     * @param Node   $node - Statement or expression to walk.
     * @param string $variableName - Caught variable's name, without the dollar sign.
     *
     * @return bool|null - True for a read, false for a rebinding or drop, null when the node never mentions the variable.
     */
    private function firstUseOf(Node $node, string $variableName): ?bool
    {
        // A function, class or method declared here has its own variables, even when one shares the name.
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod) {
            return null;
        }

        // A closure sees the caught exception only through its `use` list, which reads it.
        if ($node instanceof Expr\Closure) {
            // `function () use ($error) { ... }` carries the exception into the closure.
            foreach ($node->uses as $closureUse) {
                // A matching name in the use list is a read of the caught value.
                if ($closureUse->var->name === $variableName) {
                    return true;
                }
            }

            return null;
        }

        // A plain mention that no binding form below claimed is a read, such as `error_log($error->getMessage())`.
        if ($node instanceof Expr\Variable) {
            return $node->name === $variableName ? true : null;
        }

        $bindingOutcome = $this->useInBindingForm($node, $variableName);
        // An assignment, catch, unset, isset or foreach already decided whether the value was read or dropped.
        if ($bindingOutcome !== 0) {
            return $bindingOutcome === 1;
        }

        // Otherwise walk the children in source order until one mentions the variable.
        foreach ($node->getSubNodeNames() as $subNodeName) {
            // A child slot holds one node or a list of nodes, such as the statements of an if body.
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
     * Decides the first use inside a statement that can bind or drop variables.
     *
     * @param Node   $node - Statement or expression to inspect.
     * @param string $variableName - Caught variable's name, without the dollar sign.
     *
     * @return int - 1 for a read, -1 for a rebinding or drop of the variable, 0 when the node binds nothing and its children decide.
     */
    private function useInBindingForm(Node $node, string $variableName): int
    {
        $isCaughtName = static fn(mixed $target): bool => $target instanceof Expr\Variable && $target->name === $variableName;

        // `$message = $error->getMessage()` reads before it binds; `$error = null` drops the exception.
        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef) {
            $rightSideUse = $this->firstUseOf($node->expr, $variableName);
            // The right-hand side runs first, so a read there handles the exception before any rebinding.
            if ($rightSideUse !== null) {
                return $rightSideUse ? 1 : -1;
            }

            return $isCaughtName($node->var) ? -1 : 0;
        }

        // A second `catch (Exception $error)`, `unset($error)` or `isset($error)` leaves the earlier exception unhandled.
        if ($node instanceof Stmt\Catch_ || $node instanceof Stmt\Unset_ || $node instanceof Expr\Isset_) {
            $targets = $node instanceof Stmt\Catch_ ? [$node->var] : $node->vars;

            return array_filter($targets, $isCaughtName) !== [] ? -1 : 0;
        }

        // `foreach ($items as $error)` rebinds the name, though the loop's own expression runs first and may read it.
        if ($node instanceof Stmt\Foreach_ && ($isCaughtName($node->keyVar) || $isCaughtName($node->valueVar))) {
            return $this->firstUseOf($node->expr, $variableName) === true ? 1 : -1;
        }

        return 0;
    }
}
