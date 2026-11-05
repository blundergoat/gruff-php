<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Cache;

use FilesystemIterator;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\SeverityThreshold;
use GruffPhp\Rules\RuleRegistry;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Computes the content-addressed cache-key inputs for a run and its files.
 *
 * The run digest folds in every input that affects a per-unit rule's findings -
 * the gruff version, the analyser's own source bytes, the PHP version floor, the
 * naming/secret allowlists, and the full enabled-rule set with each rule's resolved
 * settings - so any change to what gruff checks, or how, yields a new key (a
 * guaranteed cache miss). On any doubt, a superset of inputs is safe: it only
 * invalidates more, never serves stale.
 *
 * The source bytes are there because a version string cannot see a rule that changed
 * between two releases: a source checkout kept serving findings an older rule had
 * written, so `analyse` disagreed with `summary`, which never reads the cache.
 */
final readonly class AnalysisFingerprint
{
    /**
     * The analyser's own source tree, whose bytes decide what every rule reports.
     */
    private const IMPLEMENTATION_ROOT = __DIR__ . '/../..';

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
     * @param string         $implementationRoot - Directory whose PHP sources are the analyser; tests point it at a fixture.
     * @throws JsonException When the run payload cannot be encoded.
     *
     * @return self - Fingerprint for the run.
     */
    public static function forRun(
        RuleRegistry $registry,
        AnalysisConfig $config,
        string $toolVersion,
        string $implementationRoot = self::IMPLEMENTATION_ROOT,
    ): self {
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

        $payload = json_encode([
            'version' => $toolVersion,
            'implementation' => self::implementationDigest($implementationRoot),
            'minimumPhpVersion' => $config->minimumPhpVersion(),
            'deepScanBudget' => $config->deepScanBudget(),
            'acceptedAbbreviations' => $acceptedAbbreviations,
            'rules' => $rules,
        ], JSON_THROW_ON_ERROR);

        // The run digest is the SHA-256 of every run-level input; any config or rule-set change yields a fresh key.
        return new self(hash('sha256', $payload));
    }

    /**
     * Digests every PHP source under the analyser's root, in path order, so an edited rule or helper changes the
     * run digest even when the version string did not move. Reads each file once per run; an unreadable root
     * digests as empty rather than failing the scan, which only ever invalidates more.
     *
     * @param string $implementationRoot - Directory to digest.
     *
     * @return string - Hex digest of the relative paths and bytes of every PHP file beneath the root.
     */
    private static function implementationDigest(string $implementationRoot): string
    {
        $root = realpath($implementationRoot);
        if ($root === false || !is_dir($root)) {
            return hash('sha256', '');
        }

        $paths = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        $digest = hash_init('sha256');
        foreach ($paths as $path) {
            // The relative path is digested beside the bytes, so a moved or renamed source also changes the key.
            hash_update($digest, substr($path, strlen($root)) . "\0" . (string)hash_file('sha256', $path) . "\0");
        }

        return hash_final($digest);
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
