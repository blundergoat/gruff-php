# gruff CLI instructions for coding agents

This file is the quick-start surface for agents that need to inspect a PHP project with gruff. Prefer these commands over inventing flags or parsing human text output.

## What gruff optimises for

You are a coding agent, and a human who didn't write this code has to read, review, and trust it. gruff governs the code you produce so that reviewer can verify it does what was asked — legible enough to read, secure where the eye fails, and tested for real rather than padded with low-signal ceremony. Treat its findings as a checklist for "would a human sign this off?", not as arbitrary style nags.

Doc comments are mandatory even on a private one-liner, and that is deliberate. Coding agents routinely produce code that superficially works while misunderstanding the requirement; stating intent, usage, contract, and failure behaviour in prose gives the reviewer something to check the implementation against. A mismatch between the doc comment and the code is itself a signal the change needs a deeper look — so write the intent, never a restatement of the signature.

## Ground Rules

- Run commands from the repository root unless `--project` is documented for that command.
- Use `php bin/gruff-php analyse ...` in this checkout.
- Default config auto-loads from `.gruff-php.yaml` at the project root when present (legacy `.gruff.yaml` is still accepted when `.gruff-php.yaml` is absent).
- Default baseline auto-loads from `gruff-baseline.json` at the project root when present.
- Full-project analysis is the default. Diff mode is opt-in with `--diff`.
- Git is only required for diff mode. Full-project scans work outside Git worktrees.

## Check Available Commands

```bash
php bin/gruff-php list
php bin/gruff-php analyse --help
php bin/gruff-php report --help
php bin/gruff-php dashboard --help
```

As of this file, supported `analyse` formats are:

```text
text, json, html, markdown, github, hotspot, sarif
```

Rule metadata is discoverable from the registry:

```bash
php bin/gruff-php list-rules
php bin/gruff-php list-rules --format=json
```

## Full Project Scans

Use full-project mode when you need the complete current quality picture for selected paths.

```bash
php bin/gruff-php analyse src --format text --fail-on none
```

Agent-friendly JSON:

```bash
php bin/gruff-php analyse src --format json --fail-on none > /tmp/gruff-full.json
```

PR-comment style Markdown:

```bash
php bin/gruff-php analyse src --format markdown --fail-on none > /tmp/gruff-full.md
```

HTML for manual inspection:

```bash
php bin/gruff-php analyse src --format html --fail-on none > /tmp/gruff-full.html
```

`--fail-on none` keeps the command exit code green while you inspect findings. Use `--fail-on warning` or `--fail-on error` only when you want the scan to gate CI or fail the agent step.

## Diff Scans

Use diff mode when you need findings touching changed lines or changed files. Diff mode still analyses the selected paths, then filters findings to Git changes.

Working tree compared with `HEAD`:

```bash
php bin/gruff-php analyse src --diff --format markdown --fail-on none
```

Staged changes only:

```bash
php bin/gruff-php analyse src --diff=staged --format markdown --fail-on none
```

Unstaged changes only:

```bash
php bin/gruff-php analyse src --diff=unstaged --format markdown --fail-on none
```

Compare the working tree to a base ref:

```bash
php bin/gruff-php analyse src --diff=<base-ref> --format json --fail-on none > /tmp/gruff-diff.json
```

`--diff=<base>` semantics use Git diff filtering against that ref. It is still a changed-line/file filter, not base/current finding subtraction.

## Ignored Paths

`paths.ignore` is authoritative in every invocation mode, including the explicit-path and diff scans a hook uses: a matching path is excluded from analysis and produces no findings, however it was supplied. `--include-ignored` opts back into Git/default ignores only; it never overrides `paths.ignore`. Every ignored path is reported in the JSON report's `ignoredPathDetails` (each with `source` and `pattern`) alongside the `ignoredPaths` string list.

Ask whether gruff would ignore a path, and why, without running an analysis:

```bash
php bin/gruff-php check-ignore --format json src/App.php legacy/Old.php
```

The JSON `[{ "path", "ignored", "source", "pattern" }]` is the agent contract; exit codes mirror `git check-ignore` (0 = at least one ignored, 1 = none, 2 = error). Use it to drop out-of-scope changed files before calling `analyse`.

## Branch Review / Introduced Findings

Use branch-review mode when you need the answer to "what did this branch make worse?" Load [`gruff-cli-branch-review.md`](./gruff-cli-branch-review.md) for the full agent playbook, including the recommended no-path command.

Quick JSON command from the target project root:

```bash
php /path/to/gruff-php/bin/gruff-php analyse --diff-vs=<base-ref> --changed-only --no-config --no-baseline --format=json --fail-on=none > /tmp/gruff-review.json
```

With `--changed-only` and no explicit paths, gruff derives changed files from Git internally. Do not wrap the command in a separate `git diff | mapfile` step unless intentionally forcing a custom path list. Replace `<base-ref>` with the branch or ref you review against.

## Full Project vs Diff

Full project:

- Best for baselines, trend history, rule calibration, and complete audits.
- Reports all findings under the selected paths.
- Can evaluate stale baseline entries.

Diff:

- Best for code review and local branch checks.
- Reports findings on changed lines when line ranges are available.
- Falls back to changed-file filtering for findings without line ranges.
- Requires a Git worktree.
- Does not prove a finding is newly introduced if the same logical issue already existed on the base branch.

Branch review:

- Best for coding-agent review comments.
- Separates `introduced`, `removed`, and `unchanged` findings against a base ref.
- Use `--changed-only` to compare only files changed from the base ref.
- With no explicit paths, derives changed files from Git internally.
- Requires Git, but does not mutate the working tree.
- Project-level rules need full context. A zero count for `design.single-implementor-interface` or future `ProjectRuleInterface` rules under `--changed-only` is not proof of cleanliness; run a full-project scan and intersect relevant findings with changed files when those rules matter.

When in doubt, run both:

```bash
php bin/gruff-php analyse src --format json --fail-on none > /tmp/gruff-full.json
php bin/gruff-php analyse src --diff --format json --fail-on none > /tmp/gruff-diff.json
php bin/gruff-php analyse --diff-vs=<base-ref> --changed-only --no-config --no-baseline --format json --fail-on none > /tmp/gruff-review.json
```

## Config

Default config:

```bash
.gruff-php.yaml
```

`.gruff.yaml` is still auto-loaded as a legacy fallback when `.gruff-php.yaml`
is absent.

Use an explicit config:

```bash
php bin/gruff-php analyse src --config=.gruff-php.yaml --format json --fail-on none
```

Set one threshold for a metric rule:

```yaml
rules:
    complexity.cognitive:
        enabled: true
        threshold: 30
        severity: error
```

Use `threshold` + `severity` for rules with warning/error metric defaults. Keep `thresholds` for named tuning values such as `minPositionalArguments` or `entropy`.

Tune rule options only with names listed by `list-rules --format=json`. For
example, `size.parameter-count` supports a constructor-specific cap while
leaving ordinary methods/functions/closures on the main threshold:

```yaml
rules:
    size.parameter-count:
        threshold: 10
        severity: error
        options:
            constructorMaxParameters: 0
            promotedConstructorMaxParameters: 25
```

`constructorMaxParameters: 0` means constructors inherit the main threshold.
Set it above zero only when non-exempt constructors should use a separate cap.

Skip config for one run:

```bash
php bin/gruff-php analyse src --no-config --format json --fail-on none
```

Do not combine `--config` and `--no-config`.

## Baselines

Default baseline:

```bash
gruff-baseline.json
```

Apply the default baseline automatically:

```bash
php bin/gruff-php analyse src --format json --fail-on none
```

Skip baseline for one run:

```bash
php bin/gruff-php analyse src --no-baseline --format json --fail-on none
```

Generate or refresh a baseline deliberately:

```bash
php bin/gruff-php analyse src --generate-baseline --format text --fail-on none
```

Only update `gruff-baseline.json` when accepting known findings is intentional and reviewable.

Read baseline movement to see how debt changed. Every applied-baseline run classifies findings into three buckets, exposed in JSON at `baseline.buckets` and summarised as a one-line "Movement" view in text, markdown, and HTML:

- **new** — present this run, not in the baseline (the set a new-findings gate would block);
- **unchanged** — matched a baseline entry (accepted debt, removed before scoring);
- **resolved** — a baseline entry with no matching finding this run (a fixed item).

```bash
php bin/gruff-php analyse src --baseline --format json --fail-on none | jq '.baseline.buckets'
```

Pass `--baseline-include-absent` to list the resolved entries in text, markdown, and HTML output (off by default to keep PR comments short). In diff-scoped runs the resolved bucket is reported as zero, because baseline entries outside the diff are not evaluated.

## Output Formats for Agents

Use JSON for post-processing:

```bash
php bin/gruff-php analyse src --format json --fail-on none > /tmp/gruff.json
```

Important JSON fields:

- `schemaVersion`
- `run`
- `summary`
- `findings[]`
- `findings[].ruleId`
- `findings[].file`
- `findings[].line`
- `findings[].symbol`
- `findings[].severity`
- `findings[].fingerprint`
- `diff`
- `baseline`
- `score`
- `review` when `--diff-vs` is used
- `run.filters` when display filters are used

Use Markdown when posting a short human report:

```bash
php bin/gruff-php analyse src --format markdown --fail-on none > /tmp/gruff.md
```

Use GitHub annotations in Actions:

```bash
php bin/gruff-php analyse src --format github --fail-on warning
```

Use SARIF for GitHub Code Scanning ingestion:

```bash
php bin/gruff-php analyse src --format sarif --fail-on none > /tmp/gruff.sarif
```

## Display Filters

Display filters run after analysis and before rendering. They change report contents, not rule execution, scoring, or baseline generation semantics.

```bash
php bin/gruff-php analyse src --format markdown --fail-on none --min-severity warning
php bin/gruff-php analyse src --format json --fail-on none --include-pillar security,sensitive-data
php bin/gruff-php analyse src --format json --fail-on none --exclude-rule docs.missing-public-phpdoc
php bin/gruff-php analyse src --format json --fail-on none --include-rule complexity.cyclomatic
```

## Dashboard

Start the local dashboard:

```bash
php bin/gruff-php dashboard src --host 127.0.0.1 --port 8765
```

Start with diff mode selected:

```bash
php bin/gruff-php dashboard src --diff --host 127.0.0.1 --port 8765
```

The dashboard config field defaults to `.gruff-php.yaml` (or legacy `.gruff.yaml` when present). The scan scope selector maps `whole branch` to full selected-path analysis and `diff only` to `analyse --diff`.

## Exit Codes

- `0`: no diagnostics and no finding at or above the `--fail-on` threshold.
- `1`: findings met the `--fail-on` threshold.
- `2`: setup, config, parse, input, or diagnostic errors.

For exploratory agent runs, prefer:

```bash
--fail-on none
```

For CI gating, choose the policy explicitly:

```bash
--fail-on warning
--fail-on error
```

## Failure Conditions (count gate)

`--fail-on <severity>` is a binary gate. For a count-based policy — "allow N findings at a severity, fail above" — set `failureConditions:` in `.gruff-php.yaml`:

```yaml
failureConditions:
  total: 200
  severityThresholds:
    error: 0
    warning: 5
    advisory: 50
```

"allow N" means the run passes at count ≤ N and fails at count > N; `error: 0` is the legacy "fail on any error". Any threshold that trips — a severity cap or the `total` cap — fails the run. An explicit `--fail-on` flag overrides `failureConditions`; with neither set, the gate is unchanged from before. When the gate trips, the JSON report carries a top-level `failureReason` (`{thresholdKind, count, cap, message}`) and text/markdown print a one-line `Failed: …`, so CI logs explain *why* without a re-run. Baselined findings are excluded from the count (the gate sees the post-baseline set).

## Current Gaps to Avoid Assuming

- `--diff=<base>` is a changed-line/file filter, not a full base/current subtraction engine.
- Packagist publication is release-process work outside this CLI. The package
  itself exposes Composer/local checkout usage through `bin/gruff-php` and
  `vendor/bin/gruff-php`.
