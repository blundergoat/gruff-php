<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\SourceTextRuleInterface;

/**
 * Flags Composer scripts that run shell or remote commands at install time.
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
     * Describe the risky Composer script rule.
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
     * Find `scripts` entries that run shell or remote commands.
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
        foreach ($manifest['scripts'] as $event => $commands) {
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
     * Decide whether any command for an event is a shell/remote invocation.
     *
     * @param mixed $commands - Script value: a command string or a list of commands.
     *
     * @return bool - True when at least one command matches a risky shell fragment.
     */
    private function hasRiskyCommand(mixed $commands): bool
    {
        $normalizedCommands = is_array($commands) ? $commands : [$commands];

        foreach ($normalizedCommands as $command) {
            if (!is_string($command)) {
                continue;
            }

            $normalized = strtolower($command);
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
