<?php

declare(strict_types=1);

namespace GruffPhp\Hook;

use GruffPhp\Finding\Finding;

/**
 * Classifies native findings into the agent-hook scope enum.
 */
final readonly class HookFindingScope
{
    public const LINE    = 'line';
    public const SYMBOL  = 'symbol';
    public const FILE    = 'file';
    public const PROJECT = 'project';

    /**
     * Rules whose finding describes the whole file rather than one symbol or one line.
     *
     * @var array<string, true>
     */
    private const FILE_SCOPE_RULE_IDS = [
        'docs.missing-file-phpdoc' => true,
        'docs.todo-density' => true,
        'size.file-length' => true,
    ];

    /**
     * Return the hook-contract scope for one finding.
     *
     * @param Finding $finding - Native finding emitted by a rule.
     *
     * @return string - one of line, symbol, file, or project.
     */
    public static function classify(Finding $finding): string
    {
        if (isset(self::FILE_SCOPE_RULE_IDS[$finding->ruleId])) {
            return self::FILE;
        }

        if ($finding->line === null) {
            return self::PROJECT;
        }

        if ($finding->symbol !== null) {
            return self::SYMBOL;
        }

        if ($finding->endLine !== null && $finding->endLine > $finding->line) {
            return self::SYMBOL;
        }

        return self::LINE;
    }
}
