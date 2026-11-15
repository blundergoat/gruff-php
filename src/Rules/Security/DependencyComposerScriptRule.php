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
 * Flags install-time Composer script events whose command strings contain configured risky fragments.
 * Matches substrings such as `curl`, `eval ` and backticks without parsing commands or proving execution.
 *
 * Scans `composer.json`; each matching event produces one warning with medium confidence.
 */
final class DependencyComposerScriptRule implements SourceTextRuleInterface
{
    /**
     * Stable rule identifier for risky Composer script findings.
     */
    public const ID = 'security.dependency-composer-script';

    /**
     * Substring candidates for shell or remote execution; matches can also occur in PHP callables or aliases.
     *
     * @var list<string> - Lower-case fragments matched anywhere in command strings.
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
     * @var list<string> - Event names eligible for risky-command inspection.
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
        // Medium confidence by default: fragment matching finds shell or remote-command indicators but cannot establish intent or harm.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Composer install-time shell script',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape'      => 'A benign lifecycle command that merely contains a matched fragment, such as a `php -r` version probe or a backtick inside a quoted message.',
                    'mitigation' => 'Matching is substring-based rather than shell parsing, so move the step to a reviewed PHP callable or accept the finding.',
                ],
            ],
        );
    }

    /**
     * Reports each install-time Composer script event containing a configured risky substring.
     *
     * @param AnalysisUnit $analysisUnit - Source unit supplying the manifest path and JSON text.
     * @param RuleContext $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per matching event, regardless of command count; empty when no event matches.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ComposerManifest::isManifest($analysisUnit->file->displayPath)) {

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
     * Reports whether any string command contains a configured risky substring.
     *
     * @param mixed $commands - Event value; strings are scanned and non-string entries are ignored.
     *
     * @return bool - True for a case-insensitive match of a configured fragment; false when no string command matches.
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

                    return true;
                }
            }
        }

        // No configured fragment matched; this does not establish that the event is safe.
        return false;
    }
}
