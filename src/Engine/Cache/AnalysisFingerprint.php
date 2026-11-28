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
 * Computes run and file fingerprints for cached per-file findings.
 * The run digest includes the tool version, analyser PHP sources, PHP floor, scan budget,
 * accepted abbreviations, and settings for every enabled rule.
 *
 * Hashing analyser sources invalidates entries after local rule edits without a version change.
 * The default source digest excludes vendor dependencies and non-PHP files.
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
     * @param string $runDigest - Digest incorporated into every per-file key for this run.
     */
    private function __construct(private string $runDigest)
    {
    }

    /**
     * Builds the run digest from the tool version, analyser PHP sources, PHP floor, scan budget,
     * accepted abbreviations and the resolved settings of every enabled rule.
     *
     * @param RuleRegistry $registry - Registry whose enabled-rule set is part of the key.
     * @param AnalysisConfig $config - Resolved configuration whose settings affect findings.
     * @param string $toolVersion - gruff version string folded into the key.
     * @param string $implementationRoot - Root whose PHP paths and contents enter the digest.
     * @throws JsonException - When the run payload cannot be encoded.
     *
     * @return self - Fingerprint shared by the run's per-file keys.
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
     * Builds a file's cache key from the run digest, display path and content hash.
     * The path distinguishes identical file contents because it is part of each finding's identity.
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
