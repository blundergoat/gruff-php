# Gruff PHP Rules

Run `vendor/bin/gruff-php list-rules <rule-id>` to see a per-rule detail view
including the description, default options with one-line explanations,
escape-hatch config paths (`rules.<id>.options.*`, `enabled`, `excludeFromScore`,
etc.), and any catalogued false-positive shapes with mitigations. Pass
`--format=json` for the same payload in structured form. A typo prints up
to three near-match suggestions and exits with code 2.



This rule catalogue is generated from `php bin/gruff-php list-rules --format json`.
Use that command for the full machine-readable metadata, including thresholds and options.

Total rules: 128

## False-positive guidance

74 of the 128 rules publish `falsePositiveShapes`: a list of shapes the detector is
known to misfire on, each paired with the mitigation that answers it. Every rule at
`medium` or `low` confidence carries at least one, because a heuristic rule owes the
reader the cases where its heuristic is wrong. A rule that catalogues nothing omits
the field rather than publishing an empty list, so an absent field means "nothing
catalogued yet", never "reviewed and found to have no false positives".

The catalogue (`list-rules --format=json`) and the per-rule detail view publish the
same guidance text. Both read it from the rule's own `RuleDefinition`, which is the
single place this text is written.

## Remediation action metadata

Selected findings carry a machine-readable `metadata.remediationAction`. The
action classification itself does not change severity, score, or exit-code
behaviour:

- `APPLY` marks a direct source fix.
- `CONSIDER` marks optional or compatibility-sensitive advice that needs human
  judgement.
- `CONFIGURE` is reserved for a deterministic configuration-only resolution;
  no rule emits it unconditionally in 0.5.1.

When a deliberate configuration hatch exists, `metadata.configurationKey`
contains its full path. Regex comments, missing constant documentation, and
one-line wrappers emit `APPLY`; abbreviation and named-argument findings emit
`CONSIDER`; Boolean naming emits `APPLY` for private property and private
callable names, while every parameter and other caller-visible declaration
emits `CONSIDER`. PHP named arguments make parameter-only renames
compatibility-sensitive even for private methods, promoted private state,
closures, and arrow functions.
JSON, hook, and SARIF transport these fields. Text and Markdown keep their
existing finding presentation in 0.5.1.

## Summary By Pillar

| Pillar | Rules |
| --- | ---: |
| `complexity` | 4 |
| `dead-code` | 10 |
| `documentation` | 15 |
| `maintainability` | 2 |
| `modernisation` | 9 |
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

### `dead-code` (10)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
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

`dead-code.unused-private-method` counts callable references as real
usage when the method name is statically visible: `[$this, 'method']`,
`[self::class, 'method']`, `[ClassName::class, 'method']`, and PHP 8.1
first-class callable syntax such as `self::method(...)`. Dynamic
callable names remain conservative and do not mark every private method
as used.

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

`docs.missing-constant-phpdoc` distinguishes missing documentation from
useful local documentation. By default, constants may use an immediately
preceding meaningful `//`, `#`, or block comment when the prose is useful
to a human reviewer and attached directly to the constant. A short
consecutive constant group can share one local group comment when the
comment names the group, such as supported roles or keys. Existing group
words such as `keys` and `values` retain contiguous uncapped coverage. New
`patterns` and `regexes` family comments cover at most five declared names,
including multiple names in one statement; the sixth and later names need a
new group comment, and a visibility change ends the bounded family. A mixed
comment such as `keys and patterns` uses the shipped uncapped behavior.
Findings beyond that five-name cap retain the nearby comment kind and expose
`commentQuality: bounded-group-overflow`, `groupCoverageExceeded: true`, and
`groupCoverageLimit: 5`, instead of claiming that no nearby comment exists.
Missing comments, detached comments, structural boundaries, generic comments
such as `// constant`, and comments that only duplicate the constant name
still fire.

Projects that publish constants as API documentation can opt back into
PHPDoc for public/protected constants with
`requirePhpdocForApiConstants: true`, or only for exported paths with
`apiPathPatterns`.

`docs.regex-comment` resolves purpose documentation from the narrowest source
outwards: an own-line comment immediately above the configured call, an
own-line comment directly above its nearest statement owner, an own-line
comment directly above the first statement in a physically contiguous sibling
run where every statement owns a configured regex call, a string-labelled
`match (true)` arm, then the nearest callable's contract. Group coverage ends
at a blank line, new comment, unrelated statement, branch, or callable
boundary. Statement ownership never crosses a nested callable boundary.
Blank-line-separated comments and a previous statement's trailing same-line
comment do not count even when PHP-Parser attaches them to the next node. A
block comment followed by executable code on the same line documents that code,
not a regex statement on the next line.

A callable docblock containing `regex`, `pattern`, `preg_`, or the configured
function name covers only a callable that directly owns exactly one configured
regex call. Plain-language whitespace-fold prose is accepted only when the
call is statically the exact three-argument
`preg_replace('/\s+/', ' ', $subject)` transformation. Larger or unrelated
callables still need local purpose comments for their configured calls.

`docs.missing-property-phpdoc` accepts a physically attached `//` or `#`
comment in place of a docblock when `options.acceptLineComments` is true.
The comment must carry meaning beyond what the property name already
says. The default is false, so a docblock is required.

### `maintainability` (2)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `complexity.maintainability-index` | Maintainability index | `advisory` | `medium` | yes |
| `waste.one-line-method` | One-line method | `advisory` | `medium` | yes |

`waste.one-line-method` ships with `minInFileCallers: 2` and
`namedAlternativeFactoryExempt: true`. The first skips wrappers that are
called from two or more sites in the same file (centralising shared
logic is the wrapper's job). The second skips public static named
factories such as `Money::fromCents()` because the method name is the
contract. The rule also skips local interface/abstract/trait contract
implementations, explicit `#[Override]` methods, public APIs on readonly
data carriers, domain predicates, and cast/normalisation boundaries.
Private pass-through helpers with no contract or semantic boundary still
fire, including zero-argument wrappers around another call. Override via
the per-rule `options` block; the `allowedSymbols` list is the
per-project escape hatch for named helpers that intentionally stay thin.

An exact same-class named callback is also a contract boundary. The rule
recognises PHP first-class callable syntax and two-element callable arrays whose
receiver resolves to `$this`, `self::class`, `static::class`, `__CLASS__`, or
the fully resolved declaring class. Resolution uses declaring class plus a
case-insensitive method name. Foreign, unresolved, computed, string, and
child-class-name references to a parent declaration remain conservative and
still need `allowedSymbols` when framework wiring makes them intentional.

`complexity.cyclomatic` and `complexity.cognitive` keep their raw metric
values in metadata, but flat validation flows made of top-level guard
clauses that each exit early (return, throw, or exit) are reported at
advisory severity when they cross the configured threshold. Nested
decision trees, loops, switch/match
sprawl, try/catch control flow, and mixed-responsibility methods keep the
configured warning/error severity.

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

### `modernisation` (9)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `modernisation.constructor-promotion-candidate` | Constructor property promotion candidate | `advisory` | `medium` | yes |
| `modernisation.first-class-callable-candidate` | First-class callable candidate | `advisory` | `medium` | yes |
| `modernisation.forbidden-global-access` | Forbidden direct global access | `warning` | `medium` | yes |
| `modernisation.match-expression-candidate` | Match expression candidate | `advisory` | `medium` | yes |
| `modernisation.mixed-type-overuse` | Mixed type overuse | `advisory` | `medium` | yes |
| `modernisation.named-argument-opportunity` | Named argument opportunity | `advisory` | `low` | yes |
| `modernisation.phpdoc-mixed-overuse` | PHPDoc mixed overuse | `advisory` | `medium` | yes |
| `modernisation.public-property` | Public mutable property | `warning` | `high` | yes |
| `modernisation.readonly-property-candidate` | Readonly property candidate | `advisory` | `medium` | yes |

`modernisation.forbidden-global-access` flags superglobal reads only.
Write positions — plain assignments into `$_GET`/`$_POST`/`$_SESSION`
(including nested dimension writes) and `unset()` arguments — are
request-simulation or cleanup, not boundary leaks, so they stay quiet.
Compound assignments (`.=`, `+=`) read the current value before writing
and still fire, as do reads inside a write target's dimension expression.

`modernisation.enum-candidate` was retired in 0.4.1. Constant-only
scalar classes are not a default gruff rubric because safe enum
migrations require consumer-boundary audits across serialization,
templates, JavaScript/TypeScript, telemetry, JSON, and agent/runtime
interfaces.

`modernisation.named-argument-opportunity` reports only when positional
arguments are likely to hide meaning: many positional arguments, adjacent
same-type scalar values, or boolean/null flags. Short obvious calls stay
quiet. Findings remain advisory, low-confidence `CONSIDER` suggestions because
named arguments are safest only when parameter names are a stable, intentional
API contract. An unstable API or one whose parameter names are not promised can
keep positional arguments; the finding is not evidence that the call is
incorrect. Direct `new ClassName(...)` constructor calls are outside this rule;
functions, methods, and static calls remain eligible. Raise
`minPositionalArguments` when a project wants a higher ambiguity floor.

`modernisation.public-property` covers promoted constructor properties as
well as declared ones, so upgrading can surface findings in constructors
that were never edited. Readonly promotions stay quiet. Name a fully
qualified class in `options.allowedClasses` when its mutable public state
is a deliberate lifecycle or integration contract.

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

`naming.abbreviation-allowlist` keeps short lowercase names as findings until
the project accepts them through `allowlists.acceptedAbbreviations`. Universal
programming/time vocabulary includes `dto` and `utc`; a domain term such as
`dob` is not added automatically, while four-character `uuid` lies outside the
default two-to-three-character band. Retain a domain advisory and either rename
it or document the project vocabulary. These
findings carry `CONSIDER` plus the full configuration key.

`naming.boolean-prefix` recognises both camelCase and snake_case word
boundaries after an allowed prefix: `isReady()` and `is_ready()` both
read as predicates. Common domain predicate verbs are accepted by
default, including `supports*`, `allows*`, `accepts*`, `permits*`,
`contains*`, `matches*`, `requires*`, `uses*`, `includes*`, `excludes*`,
`enables*`, and `disables*`. A prefix followed by a
lowercase letter (`hasty`, `isolate`) is not a word, and a prefix that
does not lead the name (`$force`, `$forceShould`) still fires.

The state options use tokenizer-defined whole words, never raw substrings.
`stateAdjectiveAllowlist` applies exact whole-name matching only to typed
properties and parameters; its shipped values are single-token adjectives and
now include `resolved`, `limited`, and `printable`. `stateSuffixAllowlist`
defaults to `requested`, `present`, `enabled`, and `allowed`, and
accepts them only as the final token of a name with at least two tokens across
methods, functions, parameters, promoted properties, and declared properties.
`propositionVerbAllowlist` defaults to `requires` and accepts a subject-first
name only when the verb has at least one token on each side, such as
`assistantIntentRequiresContext()`.

`acceptedBooleanNames` defaults to an empty list and matches exact whole names
case-insensitively. It is the non-breaking hatch for an intentional public
contract. Single-token Boolean callables such as `valid()`, `available()`,
`resolved()`, and `printable()` still report by default, as do vague names such
as `$data`, `$result`, `$mode`, and `status()`.

`includePublicApi` defaults to `true`. Setting it to `false` limits this rule to
private methods/properties and closure/arrow-local parameters, skipping named
functions, public/protected declarations, and their caller-visible parameters.
Retained parameter findings still carry `CONSIDER`: named arguments can target
private methods from inside their class and can target closures or arrow
functions through callable variables. Public constructor parameters likewise
remain API even when they promote private state.

`naming.identifier-quality` applies its `loopBodyThreshold` escape hatch
to inline iteration callbacks as well as foreach loops: the sole
parameter of a closure or arrow function passed directly to an
array-iteration callable (`array_filter`, `array_map`, `array_walk`,
`usort`, `uasort`, `array_reduce`, `array_any`, `array_all`,
`array_find`) is treated as a loop variable, so a generic name like
`$item` stays quiet while the callback body is below the threshold.
Longer callback bodies and generic parameters on non-iteration closures
still fire.

`naming.generic-method` reads `options.genericNames` as a replacement
list, not an addition. Setting it drops the built-in vocabulary entirely,
so repeat any built-in name the project still wants flagged. Matching is
case-insensitive.

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

`security.variable-include` treats two provable shapes as fixed paths in
addition to literals and `__DIR__`/`__FILE__`: ALL-CAPS global constants
(the `define('ABSPATH', ...)` bootstrap convention; class constants and
non-ALL-CAPS names such as `conf` stay dynamic) and locals whose every
same-scope plain assignment is itself a fixed expression, with at least
one before the include. Any tainted or second non-fixed assignment,
parameter binding, compound/by-ref write, foreach binding, `global`/
`static` declaration, or destructuring keeps the include flagged.
Constant resolution is name-based only (no cross-file `define()`
lookup); set `options.treatGlobalConstantsAsFixed: false` to disable the
constant exemption, or list specific names in
`options.dynamicPathConstants` to re-flag them.

`security.sql-concatenation` no longer flags identifier-only
interpolation through property fetches on receivers named in
`options.safeInterpolationReceivers` (default `['wpdb']`, so
`{$wpdb->prefix}`-style table prefixes pass). When the first argument's
root expression is a `prepare()` call the template argument is inspected
instead - interpolating a local into the template still flags, so
`prepare()` is never skipped wholesale. Finally, a word-bounded SQL
keyword (SELECT/INSERT/UPDATE/DELETE/ALTER/DROP/CREATE/SHOW/FROM/WHERE)
must appear in the literal fragments, which keeps non-SQL `query()`
receivers such as `DOMXPath` quiet without receiver type resolution.

`security.dangerous-function-call` adds `options.additionalFunctions` to
its built-in execution list rather than replacing it, so the built-ins
cannot be configured away. Matching is case-insensitive.

Security rules that read an argument from a global function resolve it by
the parameter name PHP declares, so `header(header: $target)` is analysed
exactly like `header($target)`. Sinks reached through a method or
constructor call - `$pdo->query()`, `$zip->extractTo()`, `new Process()` -
still match by position only, because their parameter names belong to the
library rather than to PHP.

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

`sensitive-data.high-entropy-string` no longer flags identifier- and
slug-shaped literals: a literal that contains no `+`/`=` and splits on
`[/._-]` into two or more alphanumeric segments reads as an identifier
(PHPCS sniff ids such as
`PHPCompatibility.FunctionUse.NewFunctions.ldap_exop_syncFound`, class
names such as `WPCOM_REST_API_V2_Endpoint_External_Media`, package
slugs such as `Automattic/i18n-check-webpack-plugin`), not secret
material — but only when alphabetic words of three or more characters
supply strictly more than half of all alphanumeric characters, and no
single non-word segment reaches 16 characters. The census is
character-weighted, so a couple of short dictionary words cannot
outvote a long random run: prefixed keys (`config_prod_<random>`),
slugs with hex tails (`myapp/prod-keys/<hex>`), word-prefixed digests
(`secret-key-<64-char hex>`), base64/hex tokens, npm `sha512-...`
integrity hashes, and dot-joined JWT/JWE tokens all keep flagging.

`sensitive-data.pii-test-fixture` now accepts two fixture shapes its
remediation already recommends: emails whose domain ends in a reserved
special-use TLD (`.local`, `.test`, `.invalid`, `.localhost`,
`.example`, matched at a label boundary), and addresses whose matched
tokens or surrounding line carry a synthetic marker word (`test`,
`fake`, `sample`, `demo`, `anytown`, matched as whole words). Realistic
emails, addresses without a marker, and phone numbers outside the
555-010x reserved block still flag.

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

`size.parameter-count` keeps service constructors with many dependencies
on the normal severe threshold. Final readonly promoted constructors are
treated as data carriers: they are exempt below the data-object ceiling
and advisory above it, because field count alone is not the same risk as
dependency fan-in. `size.property-count` similarly lowers final readonly
data carriers to advisory when width is the only signal, while mutable or
behaviour-heavy classes keep the configured severity.

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

`test-quality.extends-production-class` recognises a `*TestCase` parent
after ignoring underscores, so snake_case bases such as
`WC_Unit_Test_Case` or `Email_Editor_Integration_Test_Case` count as
test bases. Project bases that match neither shape (e.g.
`IntegrationTestBase`) can be declared via
`rules.test-quality.extends-production-class.options.additionalTestBaseClasses`
(exact names, compared case-insensitively against the parent's short and
fully qualified name).
