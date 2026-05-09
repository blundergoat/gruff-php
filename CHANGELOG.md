# Changelog

## Unreleased

### Added

- Composer-based analyser CLI with source discovery, parser diagnostics, and rule execution.
- Finding schema, rule registry, validated JSON config loading, and per-rule threshold overrides.
- Text and JSON analysis reports with schema versioning, diagnostics, summary counts, and documented exit codes (0 clean, 1 findings, 2 errors).
- `--format`, `--fail-on`, `--config`, and `--include-ignored` CLI options.
- ParentConnectingVisitor in the parser for enclosing class name resolution.
- PHPUnit and PHPStan level 10 quality gates for local verification.

#### Size rules (7)

- `size.file-length` — files over 400/800 lines.
- `size.class-length` — classes, traits, or enums over 300/500 lines (interfaces skipped).
- `size.method-length` — methods, functions, or closures over 30/60 lines.
- `size.parameter-count` — more than 5/8 parameters (includes promoted properties).
- `size.public-method-count` — classes with more than 15/25 public methods.
- `size.property-count` — classes with more than 15/25 properties (includes promoted).
- `size.average-method-length` — classes with average method length over 20/40 lines.

#### Complexity rules (6)

- `complexity.cyclomatic` — cyclomatic complexity over 10/20.
- `complexity.cognitive` — Sonar-style cognitive complexity over 15/30 with boolean chain collapsing and nesting penalties.
- `complexity.nesting-depth` — max nesting deeper than 4/6.
- `complexity.npath` — NPath complexity over 200/500 (clamped at 100k).
- `complexity.halstead-volume` — Halstead volume over 1000/2000.
- `complexity.maintainability-index` — MI below 65/40 (inverse threshold).

#### Dead code rules (2)

- `dead-code.unused-private-method` — private methods never called within the class (magic methods excluded).
- `dead-code.unused-private-property` — private properties never read, never written, or never used.

#### Waste rules (6)

- `waste.unreachable-code` — statements after return, throw, or exit.
- `waste.empty-method` — methods with empty bodies (abstract excluded).
- `waste.empty-class` — non-abstract classes with no members.
- `waste.unused-import` — use statements with no reference in the file.
- `waste.unused-parameter` — unused parameters in private methods and standalone functions.
- `waste.commented-out-code` — semicolon-density heuristic on comments (advisory).

#### Naming rules (7)

- `naming.generic-method` — generic names like `process()`, `handle()`, `execute()` without qualifiers.
- `naming.short-variable` — single-character variables (loop counters and catch variables excluded).
- `naming.boolean-prefix` — bool-returning methods without `is`/`has`/`can`/`should`/`will` prefix.
- `naming.hungarian-notation` — type-prefix variables like `$strName`, `$arrItems`.
- `naming.confusing-name` — standalone class names like `Helper`, `Util`, `Manager`.
- `naming.test-naming-consistency` — mixed camelCase and snake_case test methods in the same class.
- `naming.class-file-mismatch` — class name does not match filename (PSR-4).

#### Documentation rules (8)

- `docs.missing-public-phpdoc` — public methods without docblocks (getters, setters, magic methods exempt).
- `docs.missing-param-tag` — documented method missing `@param` for a parameter.
- `docs.missing-return-tag` — documented method missing `@return` for non-void return.
- `docs.missing-throws-tag` — method throws but has no `@throws` tag.
- `docs.stale-param-tag` — `@param` for parameter that no longer exists.
- `docs.useless-phpdoc` — docblock that only restates the type signature.
- `docs.todo-density` — files with more than 5/10 TODO/FIXME markers.
- `docs.missing-readme` — project root has no README.md.
