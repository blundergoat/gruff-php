<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Support\PathHelper;

/**
 * Parses the `sensitiveExclusions` config block into the reviewed, reason-bearing scopes that may
 * hide a sensitive-data finding.
 *
 * This block is deliberately separate from `selection:` so the ban on value matching is structural
 * rather than a conditional a later edit can lose: the only keys an entry may carry are `rule`,
 * `path`, `symbol`, and `reason`, and every one of them is checked here before analysis starts. A
 * wildcard rule, a pillar name, an unknown or non-sensitive rule id, an absolute or globbed path, a
 * message- or value-matching key, a missing rationale, and a second entry claiming a scope another
 * entry already owns all stop the run with a config error naming the entry index and the offending
 * key. An entry that matches nothing is not an error - it simply reports `suppressed: 0`, so fixing
 * the underlying problem never breaks a build.
 *
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key,
 *               ConfigScalar>>>>
 */
final readonly class SensitiveExclusionConfigParser
{
    /**
     * Top-level config key this parser owns, used to build every diagnostic path.
     */
    public const CONFIG_KEY = 'sensitiveExclusions';

    /**
     * The complete set of keys an entry may carry. Everything else - `message_contains`,
     * `messageContains`, `value`, and `preview` included - is rejected, because a suppression that
     * matched on reported text would reintroduce value-based suppression through the back door.
     *
     * @var list<string>
     */
    private const ENTRY_KEYS = ['rule', 'path', 'symbol', 'reason'];

    /**
     * Characters that turn a rule id into a wildcard, glob, or regular expression rather than one
     * exact id; any of them in `rule` is a blanket suppression wearing a rule id.
     */
    private const RULE_PATTERN_CHARACTERS = '*?[]{}()|+^$\\, !';

    /**
     * Glob metacharacters rejected in `path`, so an entry always names exactly one reviewed file.
     */
    private const PATH_GLOB_CHARACTERS = '*?[]{}';

    /**
     * Turns the raw `sensitiveExclusions` block into validated scopes, refusing every shape that
     * would suppress more than the reviewer actually reviewed.
     *
     * @param ConfigValue  $decodedValue - Raw `sensitiveExclusions` config value; must be a list of entry objects.
     * @param RuleRegistry $registry - Registry consulted to confirm each rule id exists and sits in the sensitive-data pillar.
     *
     * @return list<SensitiveExclusion> - validated exclusions in configuration order, so an entry's position is its audit index; empty when the
     *                                  block held no entries.
     * @throws ConfigException When the block is not a list, an entry is malformed, or two entries claim one scope.
     */
    public function parse(object|array|string|int|float|bool|null $decodedValue, RuleRegistry $registry): array
    {
        // The block has to be a list of entries; a map or a scalar means the user wrote the wrong shape.
        if (!is_array($decodedValue) || !array_is_list($decodedValue)) {
            throw new ConfigException(sprintf('Config key "%s" must be a list of exclusion entries.', self::CONFIG_KEY));
        }

        $exclusions = [];
        $scopeKeys  = [];

        // Validate each entry in turn so the first mistake names its own index.
        foreach ($decodedValue as $index => $decodedEntry) {
            $exclusion = $this->parseEntry($decodedEntry, $index, $registry);
            $scopeKey  = $exclusion->scopeKey();

            // Two entries claiming one scope would split the audit count arbitrarily, so refuse the pair.
            if (array_key_exists($scopeKey, $scopeKeys)) {
                throw new ConfigException(sprintf(
                                              'Config key "%s" duplicates the rule, path, and symbol scope already claimed by "%s"; merge them so one entry owns the scope.',
                                              $this->entryPath($index),
                                              $this->entryPath($scopeKeys[$scopeKey]),
                                          ));
            }

            $scopeKeys[$scopeKey] = $index;
            $exclusions[]         = $exclusion;
        }

        return $exclusions;
    }

    /**
     * Validates one entry end to end and builds the scope it declares.
     *
     * @param ConfigValue  $decodedEntry - Raw entry value; must be a string-keyed object.
     * @param int          $index - Entry position, named in every diagnostic this entry can raise.
     * @param RuleRegistry $registry - Registry used to resolve and classify the entry's rule id.
     *
     * @return SensitiveExclusion - the validated scope; every field is already checked, so matching never re-validates.
     * @throws ConfigException When the entry is not an object, carries an unsupported key, or holds an invalid value.
     */
    private function parseEntry(object|array|string|int|float|bool|null $decodedEntry, int $index, RuleRegistry $registry): SensitiveExclusion
    {
        // An entry must be a mapping of the supported keys; a bare string or a list is a shape mistake.
        if (!is_array($decodedEntry) || ($decodedEntry !== [] && array_is_list($decodedEntry))) {
            throw new ConfigException(sprintf('Config key "%s" must be an object.', $this->entryPath($index)));
        }

        $this->assertKnownKeys($decodedEntry, $index);

        return new SensitiveExclusion(
            ruleId: $this->ruleId($decodedEntry, $index, $registry),
            path:   $this->relativePath($decodedEntry, $index),
            symbol: $this->symbol($decodedEntry, $index),
            reason: $this->requiredString($decodedEntry, 'reason', $index),
        );
    }

    /**
     * Rejects any key outside `rule`, `path`, `symbol`, and `reason`, which is what keeps message-
     * and value-matching keys such as `message_contains`, `value`, and `preview` out of this block.
     *
     * @param array<array-key, mixed> $decodedEntry - Decoded entry whose keys are checked against the supported set.
     * @param int                     $index - Entry position, named in the diagnostic.
     *
     * @return void
     * @throws ConfigException When the entry carries any key outside the supported set.
     */
    private function assertKnownKeys(array $decodedEntry, int $index): void
    {
        // Check every key the user wrote; the first unsupported one stops the run by name.
        foreach (array_keys($decodedEntry) as $entryKey) {
            // A message- or value-matching key is the exact shape this block exists to forbid, so name it rather than ignore it.
            if (!in_array($entryKey, self::ENTRY_KEYS, true)) {
                throw new ConfigException(sprintf(
                                              'Config key "%s.%s" is not supported; a sensitive exclusion accepts only %s, and never matches on a message, value, or preview.',
                                              $this->entryPath($index),
                                              (string)$entryKey,
                                              implode(', ', self::ENTRY_KEYS),
                                          ));
            }
        }
    }

    /**
     * Resolves the entry's `rule` to exactly one known sensitive-data rule id, rejecting patterns,
     * pillar selectors, typos, and rules from any other pillar.
     *
     * @param array<array-key, mixed> $decodedEntry - Decoded entry supplying the `rule` value.
     * @param int                     $index - Entry position, named in every diagnostic below.
     * @param RuleRegistry            $registry - Registry consulted for existence and pillar classification.
     *
     * @return string - the exact rule id this entry suppresses, guaranteed to exist and to sit in the sensitive-data pillar.
     * @throws ConfigException When `rule` is missing, empty, a pattern, a pillar, unknown, or outside the sensitive-data pillar.
     */
    private function ruleId(array $decodedEntry, int $index, RuleRegistry $registry): string
    {
        $ruleId = $this->requiredString($decodedEntry, 'rule', $index);

        // A wildcard, glob, or regular expression would suppress findings nobody reviewed.
        if (strcspn($ruleId, self::RULE_PATTERN_CHARACTERS) !== strlen($ruleId)) {
            throw new ConfigException(sprintf(
                                          'Config key "%s.rule" must name exactly one rule id; wildcards, globs, and regular expressions are not accepted.',
                                          $this->entryPath($index),
                                      ));
        }

        // A pillar name is a blanket suppression wearing a rule id, so reject it on sight.
        if (Pillar::tryFrom($ruleId) instanceof Pillar) {
            throw new ConfigException(sprintf(
                                          'Config key "%s.rule" names the "%s" pillar, not a single rule id.',
                                          $this->entryPath($index),
                                          $ruleId,
                                      ));
        }

        // A typo must fail loudly rather than silently suppress nothing for the rest of the project's life.
        if (!$registry->has($ruleId)) {
            throw new ConfigException(sprintf('Config key "%s.rule" is not a known rule id: "%s".', $this->entryPath($index), $ruleId));
        }

        return $this->assertSensitiveDataRule($ruleId, $index, $registry);
    }

    /**
     * Confirms a known rule id belongs to the sensitive-data pillar, since this block governs that
     * pillar alone while ordinary rule selection stays with `selection:`.
     *
     * @param string       $ruleId - Known rule id to classify.
     * @param int          $index - Entry position, named in the diagnostic.
     * @param RuleRegistry $registry - Registry supplying the rule's declared pillar.
     *
     * @return string - the same rule id, returned once it is confirmed to sit in the sensitive-data pillar.
     * @throws ConfigException When the rule belongs to any other pillar.
     */
    private function assertSensitiveDataRule(string $ruleId, int $index, RuleRegistry $registry): string
    {
        $pillar = $registry->get($ruleId)->definition()->pillar;

        // A rule from another pillar belongs in `selection:`; suppressing it here would quietly widen this block's remit.
        if ($pillar !== Pillar::SensitiveData) {
            throw new ConfigException(sprintf(
                                          'Config key "%s.rule" must name a sensitive-data rule; "%s" belongs to the "%s" pillar.',
                                          $this->entryPath($index),
                                          $ruleId,
                                          $pillar->value,
                                      ));
        }

        return $ruleId;
    }

    /**
     * Validates the entry's `path` as exactly one project-relative file, so an exclusion can never
     * reach outside the project or cover files nobody enumerated.
     *
     * @param array<array-key, mixed> $decodedEntry - Decoded entry supplying the `path` value.
     * @param int                     $index - Entry position, named in every diagnostic below.
     *
     * @return string - the path with separators normalised to `/`, ready to compare against a finding's display path.
     * @throws ConfigException When `path` is missing, empty, absolute, escapes the project, or carries glob syntax.
     */
    private function relativePath(array $decodedEntry, int $index): string
    {
        $relativePath = PathHelper::normalizeSeparators($this->requiredString($decodedEntry, 'path', $index));

        // An absolute path leaks the author's machine layout, and a `..` climb escapes the analysed project.
        if (PathHelper::isAbsolute($relativePath) || $relativePath === '..' || str_contains($relativePath, '../')) {
            throw new ConfigException(sprintf(
                                          'Config key "%s.path" must be a relative project path that stays inside the project.',
                                          $this->entryPath($index),
                                      ));
        }

        // A glob would suppress across files nobody enumerated, which is the blanket suppression this block forbids.
        if (strcspn($relativePath, self::PATH_GLOB_CHARACTERS) !== strlen($relativePath)) {
            throw new ConfigException(sprintf(
                                          'Config key "%s.path" must name exactly one file; glob syntax is not accepted.',
                                          $this->entryPath($index),
                                      ));
        }

        return $relativePath;
    }

    /**
     * Reads the optional `symbol` that narrows an entry below file scope.
     *
     * @param array<array-key, mixed> $decodedEntry - Decoded entry; an absent `symbol` leaves the entry at file scope.
     * @param int                     $index - Entry position, named in the diagnostic.
     *
     * @return string|null - the configured symbol, or null when the entry covers every occurrence in the file.
     * @throws ConfigException When `symbol` is present but not a non-empty string.
     */
    private function symbol(array $decodedEntry, int $index): ?string
    {
        // No `symbol` key means the entry stays at file scope, which is the common case.
        if (!array_key_exists('symbol', $decodedEntry)) {
            return null;
        }

        return $this->requiredString($decodedEntry, 'symbol', $index);
    }

    /**
     * Reads one required entry key as a trimmed, non-empty string, so a missing, mistyped, or blank
     * value stops the run naming both the entry index and the key - a suppression nobody explained
     * is a suppression nobody can review.
     *
     * @param array<array-key, mixed> $decodedEntry - Decoded entry to read from.
     * @param string                  $entryKey - Key to read.
     * @param int                     $index - Entry position, named in the diagnostic.
     *
     * @return string - the trimmed value; whitespace-only input never survives, so callers receive real content.
     * @throws ConfigException When the key is absent, not a string, or blank after trimming.
     */
    private function requiredString(array $decodedEntry, string $entryKey, int $index): string
    {
        $rawValue = $decodedEntry[$entryKey] ?? null;

        // A missing, non-string, or whitespace-only value is a config mistake, so point at the exact key.
        if (!is_string($rawValue) || trim($rawValue) === '') {
            throw new ConfigException(sprintf(
                                          'Config key "%s.%s" must be a non-empty string.',
                                          $this->entryPath($index),
                                          $entryKey,
                                      ));
        }

        return trim($rawValue);
    }

    /**
     * Builds the `sensitiveExclusions[N]` prefix every diagnostic uses, so a user can find the
     * offending entry without counting list items by hand.
     *
     * @param int $index - Entry position in the configured list.
     *
     * @return string - the indexed config path for that entry, matching the order the user wrote the entries in.
     */
    private function entryPath(int $index): string
    {
        return sprintf('%s[%d]', self::CONFIG_KEY, $index);
    }
}
