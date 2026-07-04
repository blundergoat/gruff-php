<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\SourceTextRuleInterface;

/**
 * Flags a Composer lifecycle script that runs a shell or remote command at install time - a `curl | sh`,
 * an `eval`, a backtick - the shape that lets `composer install` execute arbitrary code (supply-chain risk).
 *
 * Scans `composer.json` as text, matching install-time events whose command contains a risky shell fragment.
 * Warning, medium confidence - fragment matching catches the shape but cannot prove intent or harm.
 */
final class DependencyComposerScriptRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for risky Composer script findings.
     */
    public const ID = 'security.dependency-composer-script';

    /**
     * Substrings that mark a script command as a shell/remote invocation rather
     * than a Composer PHP callable (`Class::method`) or `@`-prefixed alias.
     *
     * @var list<string>
     */
    private const RISKY_FRAGMENTS = [
        'curl',
        'wget',
        '| sh',
        '|sh',
        '| bash',
        '|bash',
        '| zsh',
        '|zsh',
        'sh -c',
        'bash -c',
        'zsh -c',
        'php -r',
        'eval ',
        '`',
    ];

    /**
     * Composer lifecycle events that run during install/update/create-project flows.
     *
     * @var list<string>
     */
    private const INSTALL_TIME_EVENTS = [
        'pre-install-cmd',
        'post-install-cmd',
        'pre-update-cmd',
        'post-update-cmd',
        'pre-autoload-dump',
        'post-autoload-dump',
        'post-root-package-install',
        'post-create-project-cmd',
        'pre-package-install',
        'post-package-install',
        'pre-package-update',
        'post-package-update',
    ];

    /**
     * Describes the risky-Composer-script rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence by default: fragment matching catches shell/remote calls but cannot prove intent or harm.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Composer install-time shell script',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Reports each install-time Composer script that runs a shell or remote command.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per script event with a risky command.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ComposerManifest::isManifest($analysisUnit->file->displayPath)) {
            // This rule only applies to composer.json; every other file yields no findings.
            return [];
        }

        $manifest = ComposerManifest::decode($analysisUnit->source);
        if ($manifest === null || !isset($manifest['scripts']) || !is_array($manifest['scripts'])) {
            // Unparseable manifest or no scripts block means there are no lifecycle commands to inspect.
            return [];
        }

        $findings = [];
        // Check each declared script event.
        foreach ($manifest['scripts'] as $event => $commands) {
            // Only an install-time event running a risky command is flagged.
            if (!is_string($event) || !in_array($event, self::INSTALL_TIME_EVENTS, true) || !$this->hasRiskyCommand($commands)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf("Composer script '%s' runs a shell or remote command at install time; review it for supply-chain risk.", $event),
                filePath:    $analysisUnit->file->displayPath,
                line:        ComposerManifest::lineOf($analysisUnit->source, sprintf('"%s"', $event)),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Replace install-time shell/remote commands with a reviewed PHP callable, or move the step out of Composer lifecycle scripts so dependency installation cannot execute arbitrary code.',
                metadata:    [
                    'event' => $event,
                ],
            );
        }

        return $findings;
    }

    /**
     * Reports whether any command for an event is a shell or remote invocation.
     *
     * @param mixed $commands - Script value: a command string or a list of commands.
     *
     * @return bool - True when at least one command matches a risky shell fragment.
     */
    private function hasRiskyCommand(mixed $commands): bool
    {
        $normalizedCommands = is_array($commands) ? $commands : [$commands];

        // Weigh each command the event runs.
        foreach ($normalizedCommands as $command) {
            // A non-string command (e.g. a nested array) is skipped.
            if (!is_string($command)) {
                continue;
            }

            $normalized = strtolower($command);
            // Check the command against each known risky fragment.
            foreach (self::RISKY_FRAGMENTS as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    // First risky fragment is enough to flag the event; no need to inspect the remaining commands.
                    return true;
                }
            }
        }

        // No command contained a shell/remote fragment, so the event is treated as a safe PHP callable or alias.
        return false;
    }
}
