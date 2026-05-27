---
category: error-handling
last_reviewed: 2026-05-27
---

# Error Handling Patterns

## Pattern: Carry an actionable hint on every user-input exception

**Created:** 2026-05-27

**Context:** User-facing CLI commands (`analyse`, `summary`, `report`, `dashboard`, `init`) validate config and other inputs through helpers that throw on failure. A bare `throw new ConfigException("Config must include schemaVersion: ...")` gives the user a message but no remediation, and each catch site must guess what to print. Inconsistent catches across commands lead to: silent swallow (`src/Command/ReportCommand.php:403`, `src/Command/DashboardStateFactory.php:185`), one-line `[CONFIG-ERROR]` prefix (`src/Command/SummaryCommand.php:239`), or structured `usageReport` (`src/Command/AnalyseCommandSetupBuilder.php:235`) — same throw site, three surfaces, none of them telling the user how to fix the underlying config. See `.goat-flow/lessons/workflow.md` "Lesson: Validator throws need an actionable hint, and the CLI catch must render it" (2026-05-27) for the reference incident.

**Approach:**

1. **Carry the hint on the exception object.** Extend `GruffPhp\Config\ConfigException` (file: `src/Config/ConfigException.php`) with an optional `string $hint = ''` constructor parameter and a `hint(): string` accessor. Default-empty preserves every existing throw site; new throw sites and the high-value retrofits supply the actionable step. Two reusable hint constants belong on the class:
   - `ConfigException::SUGGEST_INIT_FORCE` — `"Run \`gruff-php init --force\` to regenerate .gruff-php.yaml from current defaults (preserves your paths.ignore and minimumSeverity entries)."`
   - `ConfigException::SUGGEST_EDIT_CONFIG` — `"Edit .gruff-php.yaml to a supported value, or run \`gruff-php init --force\` to regenerate."`

2. **Throw with the right hint** from every validator. `assertSchemaVersion` → `SUGGEST_INIT_FORCE`. Unknown rule keys, unsupported `--fail-on` values, malformed `minimumSeverity` → `SUGGEST_EDIT_CONFIG` or a value-specific one-liner ("Use one of: advisory, warning, error, none."). Programmer-facing throws in `RuleSettings` (LogicException) stay hint-less — those signal contract bugs, not user input.

3. **Catch consistently in every Console command** with a shared renderer. Today the catch sites diverge across `SummaryCommand`, `AnalyseCommandSetupBuilder`, `ReportCommand`, and `DashboardStateFactory`. A small helper — e.g. `Console\Output\ConfigErrorRenderer::render(OutputInterface $output, ConfigException $exception): void` — should own the formatting:

   ```
   <error>gruff-php: config error
     <message></error>

   Suggested fix:
     <hint>
   ```

   Hint-less throws degrade to printing just the message line. Every Console command catches `ConfigException`, calls the renderer, and returns the appropriate exit code.

4. **Set exit code consistently.** Symfony Console's `Command::INVALID` (==2) is the documented signal for user-input errors. Don't return `Command::FAILURE` (==1) — that conflates "your config is wrong" with "something crashed mid-run".

5. **Don't catch what you can't render.** `RuntimeException`, `LogicException`, anything outside the `ConfigException` family means a code bug — let it propagate so the operator sees the stack trace and the maintainer can debug. The triage criterion is "user input or our bug?". `ConfigException` is the named seam for that decision.

6. **Lock-in test.** Add a CLI-level test (mirror `tests/Console/AnalyseCliRuntimeTest.php` shape) that runs the command against a malformed fixture config under `tests/Fixtures/Config/` and asserts both the formatted stderr/output AND the exit code. The unit-level "the exception IS thrown" assertion stays in `ConfigLoaderTest`; the CLI test locks in "the user actually sees the hint".

**Why both message and hint?** The message tells the user WHAT broke. The hint tells them WHAT TO DO. Either alone is incomplete: a message without remediation forces the user into docs; a hint without context loses the diagnostic detail. Carrying both on the exception means the throw site owns what to render, the CLI surface owns formatting, and the user gets a coherent two-line story at every error boundary regardless of which command they ran.

**Cross-port:** gruff-ts implements the same recipe with `ConfigLoadError extends Error` (file: `gruff-ts/src/config.ts`) carrying a `readonly suggestion: string` field, plus a `runWithConfigErrorHandling(action)` wrapper in `gruff-ts/src/cli-program.ts` that catches the named class, renders message + suggestion, sets `process.exitCode = 2`, and rethrows any other exception. The cross-port consistency check is "does each port's CLI surface make the user-input vs maintainer-bug distinction explicit at every throw site?"
