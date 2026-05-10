# gruff CLI instructions for coding agents

This file is the quick-start surface for agents that need to inspect a PHP project with gruff. Prefer these commands over inventing flags or parsing human text output.

## Ground Rules

- Run commands from the repository root unless `--project` is documented for that command.
- Use `php bin/gruff analyse ...` in this checkout.
- Default config auto-loads from `.gruff.yaml` at the project root when present.
- Default baseline auto-loads from `gruff-baseline.json` at the project root when present.
- Full-project analysis is the default. Diff mode is opt-in with `--diff`.
- Git is only required for diff mode. Full-project scans work outside Git worktrees.

## Check Available Commands

```bash
php bin/gruff list
php bin/gruff analyse --help
php bin/gruff report --help
php bin/gruff dashboard --help
```

As of this file, supported `analyse` formats are:

```text
text, json, html, markdown, github, hotspot
```

## Full Project Scans

Use full-project mode when you need the complete current quality picture for selected paths.

```bash
php bin/gruff analyse src --format text --fail-on none
```

Agent-friendly JSON:

```bash
php bin/gruff analyse src --format json --fail-on none > /tmp/gruff-full.json
```

PR-comment style Markdown:

```bash
php bin/gruff analyse src --format markdown --fail-on none > /tmp/gruff-full.md
```

HTML for manual inspection:

```bash
php bin/gruff analyse src --format html --fail-on none > /tmp/gruff-full.html
```

`--fail-on none` keeps the command exit code green while you inspect findings. Use `--fail-on warning` or `--fail-on error` only when you want the scan to gate CI or fail the agent step.

## Diff Scans

Use diff mode when you need findings touching changed lines or changed files. Diff mode still analyses the selected paths, then filters findings to Git changes.

Working tree compared with `HEAD`:

```bash
php bin/gruff analyse src --diff --format markdown --fail-on none
```

Staged changes only:

```bash
php bin/gruff analyse src --diff=staged --format markdown --fail-on none
```

Unstaged changes only:

```bash
php bin/gruff analyse src --diff=unstaged --format markdown --fail-on none
```

Compare the working tree to a base ref such as `deploy`:

```bash
php bin/gruff analyse src --diff=deploy --format json --fail-on none > /tmp/gruff-diff.json
```

Current `--diff=<base>` semantics use Git diff filtering against that ref. This is not yet a true introduced-findings comparison against a separately analysed base tree. M28 tracks that future branch-review mode.

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

When in doubt, run both:

```bash
php bin/gruff analyse src --format json --fail-on none > /tmp/gruff-full.json
php bin/gruff analyse src --diff --format json --fail-on none > /tmp/gruff-diff.json
```

## Config

Default config:

```bash
.gruff.yaml
```

Use an explicit config:

```bash
php bin/gruff analyse src --config=.gruff.yaml --format json --fail-on none
```

Set one threshold for a metric rule:

```yaml
rules:
    complexity.cognitive:
        enabled: true
        threshold: 30
        severity: error
```

Use `threshold` + `severity` for rules with warning/error metric defaults. Keep `thresholds` for named tuning values such as `minBodyLines`, `minPositionalArguments`, or `entropy`.

Skip config for one run:

```bash
php bin/gruff analyse src --no-config --format json --fail-on none
```

Do not combine `--config` and `--no-config`.

## Baselines

Default baseline:

```bash
gruff-baseline.json
```

Apply the default baseline automatically:

```bash
php bin/gruff analyse src --format json --fail-on none
```

Skip baseline for one run:

```bash
php bin/gruff analyse src --no-baseline --format json --fail-on none
```

Generate or refresh a baseline deliberately:

```bash
php bin/gruff analyse src --generate-baseline --format text --fail-on none
```

Only update `gruff-baseline.json` when accepting known findings is intentional and reviewable.

## Output Formats for Agents

Use JSON for post-processing:

```bash
php bin/gruff analyse src --format json --fail-on none > /tmp/gruff.json
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

Use Markdown when posting a short human report:

```bash
php bin/gruff analyse src --format markdown --fail-on none > /tmp/gruff.md
```

Use GitHub annotations in Actions:

```bash
php bin/gruff analyse src --format github --fail-on warning
```

## Dashboard

Start the local dashboard:

```bash
php bin/gruff dashboard src --host 127.0.0.1 --port 8765
```

Start with diff mode selected:

```bash
php bin/gruff dashboard src --diff --host 127.0.0.1 --port 8765
```

The dashboard config field defaults to `.gruff.yaml`. The scan scope selector maps `whole branch` to full selected-path analysis and `diff only` to `analyse --diff`.

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

## Current Gaps to Avoid Assuming

- There is no `list-rules` command yet.
- There is no SARIF output yet.
- There is no true `introduced findings only` branch-review mode yet.
- There are no `--min-severity`, `--include-pillar`, or `--exclude-rule` display filters yet.
- `--diff=<base>` is a changed-line/file filter, not a full base/current subtraction engine.

M28 tracks these improvements.
