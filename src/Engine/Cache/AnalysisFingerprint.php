<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Cache;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\SeverityThreshold;
use GruffPhp\Rules\RuleRegistry;
use JsonException;

/**
 * Computes the content-addressed cache-key inputs for a run and its files.
 *
 * The run digest folds in every input that affects a per-unit rule's findings -
 * the gruff version, the PHP version floor, the naming/secret allowlists, and the
 * full enabled-rule set with each rule's resolved settings - so any change to what
 * gruff checks, or how, yields a new key (a guaranteed cache miss). On any doubt,
 * a superset of inputs is safe: it only invalidates more, never serves stale.
 */
final readonly class AnalysisFingerprint
{
    /**
     * Wraps a precomputed run digest; build one from a run's inputs with forRun().
     *
     * @param string $runDigest - Digest of every analysis input shared across the files in a run.
     */
    private function __construct(private string $runDigest)
    {
    }

    /**
     * Builds the run fingerprint by folding every input that affects findings - tool version, PHP floor,
     * allowlists, and the full resolved rule set - into one digest, so any change forces a fresh cache key.
     *
     * @param RuleRegistry   $registry    - Registry whose enabled-rule set is part of the key.
     * @param AnalysisConfig $config      - Resolved configuration whose settings affect findings.
     * @param string         $toolVersion - gruff version string folded into the key.
     * @throws JsonException When the run payload cannot be encoded.
     *
     * @return self - Fingerprint for the run.
     */
    public static function forRun(RuleRegistry $registry, AnalysisConfig $config, string $toolVersion): self
    {
        $rules = [];
        // Fold each enabled rule and its resolved settings into the key, so changing any rule's config busts the cache.
        foreach ($registry->enabledRules($config) as $rule) {
            $ruleId         = $rule->definition()->id;
            $settings       = $config->ruleSettings($ruleId);
            $rules[$ruleId] = [
                'thresholds' => $settings->thresholds,
                'options' => $settings->options,
                'severity' => $settings->severityThreshold instanceof SeverityThreshold
                    ? [$settings->severityThreshold->threshold, $settings->severityThreshold->severity->value]
                    : null,
                'excludeFromScore' => $settings->excludeFromScore,
            ];
        }
        ksort($rules);

        $acceptedAbbreviations = $config->acceptedAbbreviations();
        sort($acceptedAbbreviations);
        $allowedSecretPreviews = $config->allowedSecretPreviews();
        sort($allowedSecretPreviews);

        $payload = json_encode([
            'version' => $toolVersion,
            'minimumPhpVersion' => $config->minimumPhpVersion(),
            'deepScanBudget' => $config->deepScanBudget(),
            'acceptedAbbreviations' => $acceptedAbbreviations,
            'allowedSecretPreviews' => $allowedSecretPreviews,
            'rules' => $rules,
        ], JSON_THROW_ON_ERROR);

        // The run digest is the SHA-256 of every run-level input; any config or rule-set change yields a fresh key.
        return new self(hash('sha256', $payload));
    }

    /**
     * Builds the cache key for one file's findings, binding the run digest, the file's display path, and
     * its content hash so the same bytes at a different path never share an entry.
     *
     * The display path is part of the key because it is part of every finding's
     * identity, so two byte-identical files at different paths never share an entry.
     *
     * @param string $displayPath - Project-relative display path.
     * @param string $contents    - Raw file bytes.
     *
     * @return string - Hex cache key for the file's per-unit findings.
     */
    public function forFile(string $displayPath, string $contents): string
    {
        // Per-file key binds the run digest, display path, and content hash; NUL separators keep them unambiguous.
        return hash('sha256', $this->runDigest . "\0" . $displayPath . "\0" . hash('sha256', $contents));
    }
}
