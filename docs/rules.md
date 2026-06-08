# Gruff PHP Rules

Run `vendor/bin/gruff-php list-rules <rule-id>` to see a per-rule detail view
including the description, default options with one-line explanations,
escape-hatch config paths (`rules.<id>.options.*`, `enabled`, `excludeFromScore`,
etc.), and any catalogued false-positive shapes with mitigations. Pass
`--format=json` for the same payload in structured form. A typo prints up
to three near-match suggestions and exits with code 2.



This rule catalogue is generated from `php bin/gruff-php list-rules --format json`.
Use that command for the full machine-readable metadata, including thresholds and options.

Total rules: 133

## Summary By Pillar

| Pillar | Rules |
| --- | ---: |
| `complexity` | 4 |
| `dead-code` | 13 |
| `design` | 1 |
| `documentation` | 15 |
| `maintainability` | 2 |
| `modernisation` | 10 |
| `naming` | 11 |
| `security` | 25 |
| `sensitive-data` | 11 |
| `size` | 7 |
| `test-quality` | 34 |

## Rule Catalogue

### `complexity` (4)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `complexity.cognitive` | Cognitive complexity | `error` | `high` | yes |
| `complexity.cyclomatic` | Cyclomatic complexity | `warning` | `high` | yes |
| `complexity.halstead-volume` | Halstead volume | `advisory` | `medium` | yes |
| `complexity.nesting-depth` | Maximum nesting depth | `error` | `high` | yes |

### `dead-code` (13)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `dead-code.unused-internal-class` | Unused internal class-like | `advisory` | `medium` | yes |
| `dead-code.unused-internal-constant` | Unused internal constant | `advisory` | `medium` | yes |
| `dead-code.unused-internal-function` | Unused internal function | `advisory` | `medium` | yes |
| `dead-code.unused-private-constant` | Unused private constant | `warning` | `high` | yes |
| `dead-code.unused-private-method` | Unused private method | `warning` | `high` | yes |
| `dead-code.unused-private-property` | Unused private property | `warning` | `high` | yes |
| `waste.commented-out-code` | Commented-out code | `advisory` | `medium` | yes |
| `waste.empty-class` | Empty class | `advisory` | `medium` | yes |
| `waste.empty-method` | Empty method | `advisory` | `high` | yes |
| `waste.redundant-variable` | Redundant variable | `advisory` | `high` | yes |
| `waste.unreachable-code` | Unreachable code | `warning` | `high` | yes |
| `waste.unused-import` | Unused import | `warning` | `high` | yes |
| `waste.unused-parameter` | Unused parameter | `warning` | `high` | yes |

### `design` (1)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `design.single-implementor-interface` | Single-implementor interface | `advisory` | `medium` | yes |

### `documentation` (15)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `docs.bare-phpdoc-tags` | Bare PHPDoc tags | `advisory` | `medium` | yes |
| `docs.missing-class-phpdoc` | Missing class PHPDoc | `advisory` | `high` | yes |
| `docs.missing-constant-phpdoc` | Missing constant PHPDoc | `advisory` | `medium` | yes |
| `docs.missing-file-phpdoc` | Missing file PHPDoc | `advisory` | `medium` | yes |
| `docs.missing-param-tag` | Missing @param tag | `advisory` | `high` | yes |
| `docs.missing-property-phpdoc` | Missing property PHPDoc | `advisory` | `medium` | yes |
| `docs.missing-public-phpdoc` | Missing method PHPDoc | `error` | `high` | yes |
| `docs.missing-readme` | Missing README | `warning` | `high` | yes |
| `docs.missing-return-tag` | Missing @return tag | `advisory` | `high` | yes |
| `docs.missing-throws-tag` | Missing @throws tag | `advisory` | `medium` | yes |
| `docs.regex-comment` | Regex comment | `advisory` | `medium` | yes |
| `docs.return-comment` | Described return tag | `advisory` | `high` | yes |
| `docs.stale-param-tag` | Stale @param tag | `warning` | `high` | yes |
| `docs.todo-density` | TODO/FIXME density | `error` | `high` | yes |
| `docs.var-annotation-description` | Var annotation description | `warning` | `high` | yes |

### `maintainability` (2)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `complexity.maintainability-index` | Maintainability index | `advisory` | `medium` | yes |
| `waste.one-line-method` | One-line method | `advisory` | `medium` | yes |

`waste.one-line-method` ships with `minInFileCallers: 2` and
`namedAlternativeFactoryExempt: true`. The first skips wrappers that are
called from two or more sites in the same file (centralising shared
logic is the wrapper's job). The second skips public static factory
pairs like `Money::fromCents()` / `Money::fromDollars()` that exist for
naming clarity. These defaults match gruff-php's own self-tuning, where
the empirical noise floor for the rule sits. Override via the per-rule
`options` block; the `allowedSymbols` list is the per-project escape
hatch for named helpers that intentionally stay thin.

`modernisation.phpdoc-mixed-overuse` exempts two type shapes that
legitimately carry a `mixed` leaf. First, unstructured array/list bag
generics — `array<string, mixed>`, `list<mixed>`, `array<int,
array<string, mixed>>` — where the rule's signal would be replacing
"mixed" with "unknown payload" prose. Second, PHPStan/Psalm `array{...}`
envelope shapes that name at least one sibling field with a non-mixed
type — e.g. `array{entries: list<array<string, mixed>>, total: int|null,
complete: bool}`. The exempted nested `mixed` describes a heterogeneous
leaf inside a typed envelope; the surrounding shape carries the meaning
the rule would otherwise demand. Loose shapes still fire: `array{value:
mixed}` (single-mixed field), `array<string|int, mixed>` (mixed-keyed
bag), `Collection<mixed>` (single-leaf generic).

### `modernisation` (10)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `modernisation.constructor-promotion-candidate` | Constructor property promotion candidate | `advisory` | `medium` | yes |
| `modernisation.enum-candidate` | Enum candidate | `advisory` | `medium` | yes |
| `modernisation.first-class-callable-candidate` | First-class callable candidate | `advisory` | `medium` | yes |
| `modernisation.forbidden-global-access` | Forbidden direct global access | `warning` | `medium` | yes |
| `modernisation.match-expression-candidate` | Match expression candidate | `advisory` | `medium` | yes |
| `modernisation.mixed-type-overuse` | Mixed type overuse | `advisory` | `medium` | yes |
| `modernisation.named-argument-opportunity` | Named argument opportunity | `advisory` | `low` | yes |
| `modernisation.phpdoc-mixed-overuse` | PHPDoc mixed overuse | `advisory` | `medium` | yes |
| `modernisation.public-property` | Public mutable property | `warning` | `high` | yes |
| `modernisation.readonly-property-candidate` | Readonly property candidate | `advisory` | `medium` | yes |

### `naming` (11)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `naming.abbreviation-allowlist` | Abbreviation allowlist | `advisory` | `high` | yes |
| `naming.boolean-prefix` | Boolean method prefix | `advisory` | `medium` | yes |
| `naming.class-file-mismatch` | Class/file name mismatch | `warning` | `high` | yes |
| `naming.confusing-name` | Confusing standalone class name | `advisory` | `medium` | yes |
| `naming.generic-method` | Generic method name | `advisory` | `medium` | yes |
| `naming.hungarian-notation` | Hungarian notation | `advisory` | `medium` | yes |
| `naming.identifier-quality` | Identifier quality | `advisory` | `medium` | yes |
| `naming.negative-boolean` | Negative boolean flag | `advisory` | `medium` | yes |
| `naming.short-variable` | Short variable name | `advisory` | `high` | yes |
| `naming.suffix-hungarian` | Suffix Hungarian notation | `advisory` | `medium` | yes |
| `naming.test-naming-consistency` | Test method naming consistency | `advisory` | `high` | yes |

### `security` (25)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `security.dangerous-function-call` | Dangerous function calls | `warning` | `medium` | yes |
| `security.debug-mode-enabled` | Debug error display enabled | `warning` | `medium` | yes |
| `security.dependency-composer-path` | Composer path repository | `warning` | `medium` | yes |
| `security.dependency-composer-script` | Composer install-time shell script | `warning` | `medium` | yes |
| `security.dependency-composer-unpinned` | Unpinned Composer dependency constraint | `warning` | `medium` | yes |
| `security.dependency-composer-vcs` | Composer VCS repository | `warning` | `medium` | yes |
| `security.disabled-ssl-verification` | Disabled SSL verification | `warning` | `high` | yes |
| `security.error-suppression` | Error suppression operator | `warning` | `high` | yes |
| `security.extract-compact-user-input` | extract or compact on request data | `warning` | `medium` | yes |
| `security.github-actions-risky-workflow` | Risky GitHub Actions workflow | `warning` | `medium` | yes |
| `security.header-injection` | Header injection risk | `warning` | `medium` | yes |
| `security.insecure-random` | Insecure random source | `warning` | `high` | yes |
| `security.path-traversal-file-access` | Path traversal file access | `warning` | `medium` | yes |
| `security.permissive-cors` | Permissive CORS with credentials | `warning` | `medium` | yes |
| `security.process-command-construction` | Process command construction | `warning` | `medium` | yes |
| `security.reflected-xss` | Reflected XSS sink | `warning` | `medium` | yes |
| `security.request-controlled-url` | Request-controlled URL | `warning` | `medium` | yes |
| `security.sensitive-data-logging` | Sensitive data logging | `warning` | `medium` | yes |
| `security.silent-catch` | Silent catch block | `warning` | `high` | yes |
| `security.sql-concatenation` | SQL string concatenation | `warning` | `medium` | yes |
| `security.unsafe-archive-extraction` | Unsafe archive extraction | `warning` | `medium` | yes |
| `security.unsafe-unserialize` | Unsafe unserialize usage | `warning` | `medium` | yes |
| `security.unsafe-xml-loading` | Unsafe XML loading | `warning` | `medium` | yes |
| `security.variable-include` | Variable include or require path | `warning` | `medium` | yes |
| `security.weak-crypto` | Weak cryptography primitives | `warning` | `high` | yes |

### `sensitive-data` (11)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `sensitive-data.api-key-pattern` | Common API key pattern | `warning` | `high` | yes |
| `sensitive-data.aws-access-key` | AWS access key | `warning` | `high` | yes |
| `sensitive-data.database-url-password` | Database URL password | `warning` | `high` | yes |
| `sensitive-data.gcp-service-account-key` | GCP service-account key | `warning` | `high` | yes |
| `sensitive-data.hardcoded-env-value` | Hardcoded environment value | `warning` | `medium` | yes |
| `sensitive-data.high-entropy-string` | High entropy string | `warning` | `medium` | yes |
| `sensitive-data.jwt-token` | JWT token literal | `warning` | `medium` | yes |
| `sensitive-data.phi-pattern` | PHI identifier pattern | `warning` | `medium` | yes |
| `sensitive-data.pii-test-fixture` | PII in test fixture | `warning` | `medium` | yes |
| `sensitive-data.private-key` | Private key material | `warning` | `high` | yes |
| `sensitive-data.url-credentials` | URL embedded credentials | `warning` | `high` | yes |

### `size` (7)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `size.average-method-length` | Average method length | `error` | `high` | yes |
| `size.class-length` | Class length | `error` | `high` | yes |
| `size.file-length` | File length | `error` | `high` | yes |
| `size.method-length` | Method length | `error` | `high` | yes |
| `size.parameter-count` | Parameter count | `error` | `high` | yes |
| `size.property-count` | Property count | `error` | `high` | yes |
| `size.public-method-count` | Public method count | `error` | `high` | yes |

### `test-quality` (34)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `test-quality.conditional-logic` | Conditional test logic | `advisory` | `high` | yes |
| `test-quality.data-provider-annotation` | Data provider annotation | `advisory` | `high` | yes |
| `test-quality.eager-test` | Eager test | `advisory` | `low` | yes |
| `test-quality.empty-data-provider` | Empty data provider | `error` | `high` | yes |
| `test-quality.exception-type-only` | Exception type-only assertion | `advisory` | `medium` | yes |
| `test-quality.excessive-mocking` | Excessive mocking | `advisory` | `medium` | yes |
| `test-quality.extends-production-class` | Test extends production class | `error` | `high` | yes |
| `test-quality.global-state-mutation` | Global state mutation in test | `warning` | `medium` | yes |
| `test-quality.loop-assertion-without-message` | Assertion in loop without message | `advisory` | `medium` | yes |
| `test-quality.magic-number-assertion` | Magic number assertion | `advisory` | `low` | yes |
| `test-quality.mock-only-test` | Mock-only test | `warning` | `medium` | yes |
| `test-quality.mock-without-expectation` | Mock without expectation | `warning` | `medium` | yes |
| `test-quality.mocking-domain-object` | Mocking a domain object | `advisory` | `low` | yes |
| `test-quality.multiple-aaa-cycles` | Multiple arrange-act-assert cycles | `advisory` | `low` | yes |
| `test-quality.mystery-guest` | Mystery guest | `advisory` | `medium` | yes |
| `test-quality.naming-consistency` | Test naming consistency | `advisory` | `high` | yes |
| `test-quality.no-assertions` | Test without assertions | `error` | `medium` | yes |
| `test-quality.phpunit-coverage-source-missing` | PHPUnit coverage source missing | `advisory` | `medium` | yes |
| `test-quality.phpunit-deprecations-not-fatal` | PHPUnit deprecations not fatal | `warning` | `high` | yes |
| `test-quality.phpunit-strict-flags-missing` | PHPUnit strict flags missing | `warning` | `high` | yes |
| `test-quality.private-reflection` | Private member reflection | `warning` | `high` | yes |
| `test-quality.repeated-structure-missing-data-provider` | Repeated test structure missing data provider | `advisory` | `low` | yes |
| `test-quality.setup-bloat` | Setup bloat | `advisory` | `medium` | yes |
| `test-quality.skipped-without-reason` | Skipped test without reason | `warning` | `high` | yes |
| `test-quality.sleep-in-test` | Sleep or wall-clock read in test | `warning` | `high` | yes |
| `test-quality.static-analysis-redundant-test` | Static-analysis-redundant test candidate | `advisory` | `high` | yes |
| `test-quality.sut-not-called` | Test name mentions SUT that is not called | `error` | `low` | yes |
| `test-quality.tautological-type-assertion` | Tautological type assertion | `error` | `high` | yes |
| `test-quality.test-longer-than-sut` | Test longer than apparent SUT | `advisory` | `low` | yes |
| `test-quality.test-method-too-long` | Test method too long | `advisory` | `high` | yes |
| `test-quality.testdox-readability` | Testdox readability | `advisory` | `low` | yes |
| `test-quality.trivial-assertion` | Trivial assertion | `warning` | `high` | yes |
| `test-quality.trivial-snapshot` | Trivial snapshot | `advisory` | `medium` | yes |
| `test-quality.unused-mock` | Unused mock variable | `advisory` | `high` | yes |
