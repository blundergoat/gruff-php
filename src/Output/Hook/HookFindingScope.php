<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Finding\Finding;

/**
 * Tags every native finding with the scope the agent-hook contract understands: `line`, `symbol`,
 * `file`, or `project`. This single decision drives what the AI coding agent actually sees, because
 * the hook filter matches `line` and `symbol` findings against the lines the edit changed, while
 * `file` and `project` findings have no single line, so in a changed-region run they survive only when
 * a baseline proves them new (a plain full scan keeps them regardless).
 * The chosen scope is also stamped into the JSON payload the agent reads back.
 *
 * Reached during a `gruff-php hook` run, once per finding, right after the rules fire on the code the
 * agent just edited.
 */
final readonly class HookFindingScope
{
    /**
     * Finding pinned to a single edited line, so the hook can point the agent at exactly one line to fix.
     */
    public const LINE    = 'line';

    /**
     * Finding that covers a whole symbol - a method, function, or class span - rather than one line, so
     * the agent reviews the symbol it touched instead of a bare line number.
     */
    public const SYMBOL  = 'symbol';

    /**
     * Finding about the source file as a whole - its length, TODO density, or a missing file docblock -
     * with no single line to blame.
     */
    public const FILE    = 'file';

    /**
     * Finding with no line to point at, so the hook treats it as project-level - though it may still name a file, like a missing `README.md`.
     */
    public const PROJECT = 'project';

    /**
     * Rules whose finding is about the file as a whole even though it may still carry a line number, so
     * they are forced to `file` scope up front rather than being mistaken for a line- or symbol-level issue.
     *
     * @var array<string, true>
     */
    private const FILE_SCOPE_RULE_IDS = [
        'docs.missing-file-phpdoc' => true,
        'docs.todo-density' => true,
        'size.file-length' => true,
    ];

    /**
     * Works out which of the four hook scopes a finding belongs to, so the hook can decide whether to
     * show it to the agent and how to describe where it lives. Called once per finding while a
     * `gruff-php hook` run assembles its feedback.
     *
     * @param Finding $finding - Native finding emitted by a rule, carrying the rule id, line, symbol, and span the scope is read from.
     *
     * @return string - One of `line`, `symbol`, `file`, or `project`; `line`/`symbol` findings get matched against the edited lines, while `file`/`project` findings have no line to match, so in a changed-region run they show only when a baseline marks them new.
     */
    public static function classify(Finding $finding): string
    {
        // Check the whole-file rules first: something like `size.file-length` still reports a line number,
        // but the finding is really about the entire file, so tag it `file` before the line and symbol
        // checks below could mistake that number for a pinpoint location.
        if (isset(self::FILE_SCOPE_RULE_IDS[$finding->ruleId])) {
            return self::FILE;
        }

        // No line at all means there's no single spot to jump to, so the hook treats the finding as
        // project-level - even if it still names a file, like a missing `README.md` - not tied to one edit.
        if ($finding->line === null) {
            return self::PROJECT;
        }

        // The rule named a specific symbol (a method, function, or class), so the finding is about that
        // whole symbol the agent touched, not a single stray line inside it.
        if ($finding->symbol !== null) {
            return self::SYMBOL;
        }

        // Even with no symbol name, a finding that spans more than one line covers a region rather than a
        // point, so treat that multi-line span as symbol scope too.
        if ($finding->endLine !== null && $finding->endLine > $finding->line) {
            return self::SYMBOL;
        }

        // Nothing broader matched: the finding sits on exactly one line, so the hook can point the agent
        // straight at the single line it just wrote.
        return self::LINE;
    }
}
