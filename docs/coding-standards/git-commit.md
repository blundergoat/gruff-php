# Git Commit Instructions


## Branch Prefix (read the branch first)

**Before writing the subject, read the current branch** (`git branch --show-current`). If the
branch is `feat/<digits>...` - leading digits right after `feat/`, e.g. `feat/123_add-cache` - the
subject MUST start with `#<digits> ` followed by the conventional-commit format:

```
#123 feat(audit): add drift cache
```

If the branch has no `feat/<digits>` prefix (e.g. `dev`, `main`, `hotfix/foo`,
`feat/no-number-slug`), do NOT add a `#` prefix - use the plain `type(scope): subject` format. The
number comes from the branch name only - never invent an issue number from the diff.

## Commit Message Format

Conventional commit format: `type(scope): subject`. Lowercase after the colon,
imperative mood, no trailing period, subject ≤72 characters.

```
type(scope): subject

Body explaining *why* this change is needed (the diff already shows what).
- bullet per axis when the change touches more than one area
- name files, behaviours, or commands by their real identifiers
```

Separate the subject from the body with a blank line. On a `feat/<digits>` branch, prepend
`#<digits> ` to the subject line (see **Branch Prefix** above).

### Picking a `type`

| Type | Use for |
|------|---------|
| `feat` | New user- or agent-visible behaviour |
| `fix` | Bug fix, regression, or incorrect behaviour |
| `refactor` | Internal change with no behaviour change |
| `test` | Adding or fixing tests only |
| `chore` | Version bumps, dependency updates, tooling |
| `docs` | Documentation-only changes |
| `security` | Security hardening, deny-policy, or sandbox change |

Pick the scope from the area that actually changed (`dashboard`, `audit-command`,
`guardrails`, `learning-loop`, `quality`, `ci`, `version`, …). One scope per
commit - if scopes diverge, split the commit.

## Subject-Line Rules (the part agents get wrong)

**Avoid weak verbs that paraphrase the diff:** *enhance, improve, streamline,
clarify, update, tweak, polish*. They tell the reader nothing the diff did not.

**Use concrete verbs naming the actual change:** *add, remove, replace, rename,
fix, deny, allow, gate, skip, harden, cache, invalidate, log, retry*.

**One observable change per subject.** If the subject contains "and", names two
axes, or starts to read like a release-note paragraph - either split the commit
or move the second axis into a bulleted body.

### Bad → Good rewrites

```
BAD:  feat(guardrails): enhance command checks for combined shell flags and git push scenarios
GOOD: feat(guardrails): deny `bash -lc` chains and protected-branch git push

BAD:  refactor(docs): streamline artifact routing instructions and enhance clarity
GOOD: refactor(docs): move artifact routing rules from CLAUDE.md to artifact-routing.md

BAD:  chore(version): bump goat-flow reference version to 1.3.1 across documentation and scripts
GOOD: chore(version): bump reference version to 1.3.1 in CLAUDE.md, AGENTS.md, and bump-version.sh
```

On a `feat/<digits>` branch the good subjects above gain an issue prefix, e.g.
`#123 feat(guardrails): deny protected-branch git push`.

## When a Body Is Required

Write a body (blank line + bullets) when **any** of these are true:

- The subject names more than one axis (touches multiple files, scopes, or behaviours).
- The change has a non-obvious motivation (perf, OS-specific bug, prior incident, compliance).
- The change is hard to bisect from the subject alone (version bumps, cross-cutting renames).

Body template - name the *why* first, then bullet each axis:

```
fix(dashboard): speed up home audit load on Windows

- replace shell-specific dashboard build steps with cross-platform Node fs calls
- skip deny hook self-tests during dashboard summary audits
- keep full deny hook validation for deeper audit/quality flows
- add regression coverage for the faster /api/audit path
```

