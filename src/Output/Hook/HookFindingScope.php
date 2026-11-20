<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Finding\Finding;

/**
 * Assigns the hook scope used by finding presentation and changed-region filtering.
 * Known whole-file rules take precedence; otherwise location and symbol fields determine the scope.
 * The filter matches line and symbol ranges and compares broader scopes against a baseline or base scan.
 */
final readonly class HookFindingScope
{
    /**
     * Finding pinned to one source line for changed-region matching.
     */
    public const LINE    = 'line';

    /**
     * Finding associated with a named symbol or a multi-line span for changed-region matching.
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
     * @var array<string, true> - Rule IDs that take file-scope precedence over location fields.
     */
    private const FILE_SCOPE_RULE_IDS = [
        'docs.missing-file-phpdoc' => true,
        'docs.todo-density' => true,
        'size.file-length' => true,
    ];

    /**
     * Classifies a finding, giving known whole-file rules priority over its location fields.
     * Otherwise, a missing line means project scope; a symbol or extended span means symbol scope.
     *
     * @param Finding $finding - Finding whose rule, line, symbol, and end line select the scope.
     *
     * @return string - File for listed rules, project without a line, symbol for a symbol/span, otherwise line.
     */
    public static function classify(Finding $finding): string
    {
        // Whole-file rules retain file scope even when they report a line or symbol, because the finding applies to the entire file.
        if (isset(self::FILE_SCOPE_RULE_IDS[$finding->ruleId])) {
            return self::FILE;
        }



        if ($finding->line === null) {
            return self::PROJECT;
        }



        if ($finding->symbol !== null) {
            return self::SYMBOL;
        }

        // An unnamed multi-line span represents a region rather than one line, so it uses symbol scope.

        if ($finding->endLine !== null && $finding->endLine > $finding->line) {
            return self::SYMBOL;
        }



        return self::LINE;
    }
}
