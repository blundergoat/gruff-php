---
category: setup
last_reviewed: 2026-08-11
---

# Setup Lessons

## Lesson: Fresh scaffold still needs real learning-loop entries for stats

**What happened:** During initial goat-flow setup, `node --import tsx src/cli/cli.ts stats /home/devgoat/projects/gruff-workspace/gruff-php --check` failed because `.goat-flow/learning-loop/footguns/` and `.goat-flow/learning-loop/lessons/` contained only README files and zero entries.

**Root cause:** The installer creates learning-loop directories, but the `stats --check` of that era treated an existing directory with zero entries as a blocking finding. Treating "fresh install" as complete without a measured entry left the stats gate red.

**No longer a blocker:** goat-flow 1.5.1 reclassified this state, in the same-day commit `27a5d97b` that followed this incident. "Footgun directory exists but contains 0 entries" and the matching lesson message are advisory `empty-learning-loop` warnings, so a fresh learning loop no longer fails `stats --check`. Malformed frontmatter and a missing `last_reviewed` still block. Confirmed unchanged through 1.15.1: `dist/cli/stats/stats.js` is byte-identical between 1.15.0 and 1.15.1.

**Prevention:** For scaffold projects, add only evidence-backed setup entries. Do not invent code incidents; document the real scaffold state and the real setup verification failure. The gate change removes the pressure to manufacture an entry just to turn the check green.

