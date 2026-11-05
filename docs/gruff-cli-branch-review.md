# gruff branch-review instructions for coding agents

Use branch-review mode when you need the answer to "what did this branch make worse?"

Branch review is different from `--diff=<base>`:

- `--diff=<base>` filters current findings to changed lines/files.
- `--diff-base=<base>` compares current findings with a base-ref snapshot and reports `introduced`, `removed`, and `unchanged` findings.

## Recommended Command

Run from the target project root:

```bash
php /path/to/gruff-php/bin/gruff-php analyse \
  --diff-base=<base-ref> \
  --changed-only \
  --no-config \
  --no-baseline \
  --format=json \
  --fail-on=none \
  > /tmp/gruff-branch-review.json
```

When running inside this checkout against this checkout, use:

```bash
php bin/gruff-php analyse \
  --diff-base=<base-ref> \
  --changed-only \
  --no-config \
  --no-baseline \
  --format=json \
  --fail-on=none \
  > /tmp/gruff-branch-review.json
```

`--diff-base=<base-ref> --changed-only` is the key combination. Replace `<base-ref>` with the branch or ref you review against. With no explicit paths, gruff derives changed files from Git internally and scopes both current-tree analysis and base snapshot comparison to those changed files. Agents should not wrap this in a separate `git diff | mapfile` command unless they intentionally need a custom path list.

Project-level rules need full project context. No bundled rule currently implements `ProjectRuleInterface` (the project rules were retired), but a zero count for any future `ProjectRuleInterface` rule under `--changed-only` is not proof that the branch is clean for that rule. When those rules matter, run a full-project scan and intersect relevant findings with changed files or review relevance after the fact.

## Optional Explicit Paths

Pass paths only when the review should be narrower than the branch diff:

```bash
php /path/to/gruff-php/bin/gruff-php analyse \
  src/Foo.php src/Bar \
  --diff-base=<base-ref> \
  --changed-only \
  --no-config \
  --no-baseline \
  --format=json \
  --fail-on=none \
  > /tmp/gruff-branch-review.json
```

Explicit directories include changed files below that directory. Explicit files include exact changed-file matches. Added files that do not exist on the base ref are valid; deleted files can still produce `removed` findings from the base side.

## Inspect The Result

```bash
php -r '$j=json_decode(file_get_contents("/tmp/gruff-branch-review.json"), true); echo "diagnostics=".json_encode($j["diagnostics"] ?? null)."\n"; echo "php_review_counts=".json_encode($j["extensions"]["php"]["topLevel"]["review"]["counts"] ?? null)."\n";'
```

Expected successful shape:

```text
diagnostics=[]
php_review_counts={"introduced":...,"removed":...,"unchanged":...}
```

If `php_review_counts` is `null`, the run published no `review` object and the branch-review comparison did not complete. Check `diagnostics` for a `diff-mode-error` first.

## Output Fields Agents Should Use

The `extensions.php.topLevel.review` object contains:

- `active`: always `true`; the object is absent altogether when no branch review ran.
- `base`: the base ref used for comparison.
- `changedOnly`: whether changed-file scoping was enabled.
- `counts.introduced`: findings present in current analysis but absent from base.
- `counts.removed`: findings present in base but absent from current analysis.
- `counts.unchanged`: findings present in both current and base.
- `introduced[]`, `removed[]`, `unchanged[]`: finding payloads.
- `deltaScore`: current score minus base score for the reviewed scope; `null` when the scan discovered no files and no current score applies.
- `perRuleDelta[]`: one row per rule whose count moved, as `{ruleId, introduced, removed, net}`, ascending by net so the largest reductions come first. A rule that introduced and removed the same number is omitted.

Line numbers are report context only. Branch review compares stable finding identity by `file + ruleId + symbol` when possible, falling back to `file + ruleId + message`.

## Fast-Path Checks

For performance smoke testing:

```bash
/usr/bin/time -f 'elapsed=%E cpu=%P maxrss_kb=%M' \
php /path/to/gruff-php/bin/gruff-php analyse \
  --diff-base=<base-ref> \
  --changed-only \
  --no-config \
  --no-baseline \
  --format=json \
  --fail-on=none \
  > /tmp/gruff-branch-review.json
```

For temporary snapshot cleanup:

```bash
find /tmp -maxdepth 1 -type d -name 'gruff-review-*' -printf '%f\n' | sort | tail -20
```

Successful and failed runs should not leave stale snapshot directories.
