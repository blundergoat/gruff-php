---
category: setup
last_reviewed: 2026-05-09
---

# Setup Lessons

## Lesson: Fresh scaffold still needs real learning-loop entries for stats

**What happened:** During initial goat-flow setup, `node --import tsx src/cli/cli.ts stats /home/devgoat/projects/gruff-workspace/gruff-php --check` failed because `.goat-flow/footguns/` and `.goat-flow/lessons/` contained only README files and zero entries.

**Root cause:** The installer creates learning-loop directories, but `stats --check` expects at least one entry when those directories exist. Treating "fresh install" as complete without a measured entry leaves the stats gate red.

**Prevention:** For scaffold projects, add only evidence-backed setup entries. Do not invent code incidents; document the real scaffold state and the real setup verification failure.

