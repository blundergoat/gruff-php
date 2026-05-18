# ADR-007: Gitignore-Aware Discovery

**Status:** Accepted
**Date:** 2026-05-16
**Ticket/Context:** M41 gitignore-aware discovery scope

## Context

`SourceDiscovery` currently decides scan eligibility with a hard-coded default ignore list plus configured `paths.ignore` overlays. That keeps generated dependency and cache paths out of analysis, but it does not use the repository's own ignore contract as the default source of truth.

The scanner has two competing jobs:

1. Broad security-oriented scans should include repository control surfaces when they are part of the working tree.
2. Broad scans should not read local or generated state that the repository has already declared out of scope.

Maintaining a second hand-written ignore policy in gruff creates drift from Git's view of the checkout. Ignoring whole control directories would reduce noise, but it would also hide files that can affect CI, automation, security posture, and agent behavior.

## Decision

For Git worktrees, default source discovery will treat Git's visible file set as the authoritative scan boundary: tracked files and untracked files not excluded by Git are eligible for analysis, subject to gruff's supported source types and configured `paths.ignore`.

Files excluded by Git are skipped by default. The scanner must not replace that behavior with a growing list of project-specific directory exclusions.

`--include-ignored` remains the explicit opt-in for callers who want to inspect files outside the default boundary. Non-Git directories and environments without a usable Git executable keep a documented filesystem fallback.

This ADR decides discovery eligibility only. It does not require gruff to parse every file format; source-type coverage remains an implementation decision for the milestone.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Keep only the hard-coded ignore list | Gruff's scan boundary drifts from the repository's own checkout policy, and local generated state can leak into broad scans. | Rejected. The repository already has a source of truth for what belongs in the working tree. |
| Add more project-specific path ignores to `.gruff-php.yaml` | The default self-scan becomes quieter, but each project must duplicate Git ignore semantics manually. | Rejected. It scales poorly and makes security scans depend on hand-maintained analyzer config. |
| Ignore whole control-surface directories | Broad scans avoid local state, but also miss committed automation and policy files that can affect security and workflow behavior. | Rejected. Hiding those surfaces is worse than scanning them. |
| Use Git-visible files as the default boundary | Discovery follows the working tree contract, while explicit opt-in remains available for ignored paths. | Accepted. This best matches security-oriented broad scans and keeps app-only scans available through explicit path arguments. |

## Consequences

- Default broad scans in Git worktrees must consult Git or an exact equivalent before deciding which files are eligible.
- Tracked and unignored control-surface files stay in scope when their file type is supported.
- Configured `paths.ignore` remains a project-level overlay for intentional analyzer exclusions.
- Built-in ignores should be treated as fallback behavior for non-Git contexts or as narrowly justified generated-artifact protections, not as a competing project policy.
- Tests must cover Git worktrees, non-Git fallback, explicit paths, configured ignores, and `--include-ignored`.

## Reversibility

Two-way door before implementation ships. Reversal requires a new ADR or an update to this one with evidence that Git-aware discovery is too slow, too platform-dependent, or materially less predictable than the current filesystem walker.

The rollback path is to keep the existing filesystem traversal as the default and limit Git-aware discovery to an explicit mode. If that happens, the security-scan trade-off must be documented because broad scans would no longer automatically follow the repository's working-tree boundary.
