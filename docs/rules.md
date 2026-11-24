# Gruff PHP Rules

Run `vendor/bin/gruff-php list-rules <rule-id>` for a single rule's detail view,
including its description, default options with one-line explanations, config
escape hatches (`rules.<id>.options.*`, `enabled`, `excludeFromScore`), and any
catalogued false-positive shapes with mitigations. Pass `--format=json` for the
structured payload. An unknown rule ID prints up to three close matches and
exits with code `2`.

This catalogue matches `php bin/gruff-php list-rules --format json`. Use that
command for complete machine-readable metadata, including thresholds and
options.

Total rules: 83

## False-positive guidance

47 of the 83 rules publish `falsePositiveShapes`: a list of shapes the detector is
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
action classification does not change severity, score, or exit codes:

- `APPLY` marks a direct source fix.
- `CONSIDER` marks optional or compatibility-sensitive advice that needs human
  judgement.
- `CONFIGURE` is reserved for a configuration-only resolution; no current rule
  emits it.

When a deliberate configuration hatch exists, `metadata.configurationKey` holds
its full path. `docs.missing-constant-phpdoc` and `docs.regex-comment` emit
`APPLY` (with `rules.docs.regex-comment.options.functionNames` as the regex
configuration key); `naming.abbreviation-allowlist` emits `CONSIDER` with
`allowlists.acceptedAbbreviations`. JSON, hook, and SARIF carry these fields;
text and Markdown omit them.

## Confidence evidence

A rule's confidence follows its measured precision: high at an error-adjusted score of 0.85 or more, medium from 0.70, low below, and a raise needs the lower bound of its 95% interval to clear the new band. These default-on rules have no measurement that sets their confidence, so the value below is inherited, not measured:

| Rule | Confidence | Measurement |
|---|---|---|
| `dead-code.unused-private-constant` | high | insufficient |
| `docs.missing-constant-phpdoc` | medium | insufficient |
| `docs.missing-readme` | high | unmeasured |
| `docs.missing-throws-tag` | medium | insufficient |
| `docs.return-comment` | high | too-few |
| `docs.todo-density` | high | insufficient |
| `modernisation.readonly-property-candidate` | medium | insufficient |
| `naming.test-naming-consistency` | high | insufficient |
| `security.dependency-composer-script` | medium | unmeasured |
| `security.extract-compact-user-input` | medium | unmeasured |
| `security.permissive-cors` | medium | unmeasured |
| `security.process-command-construction` | medium | insufficient |
| `security.reflected-xss` | medium | insufficient |
| `security.request-controlled-url` | medium | unmeasured |
| `security.unsafe-archive-extraction` | medium | unmeasured |
| `security.unsafe-xml-loading` | medium | unmeasured |
| `sensitive-data.api-key-pattern` | high | too-few |
| `sensitive-data.aws-access-key` | high | too-few |
| `sensitive-data.gcp-service-account-key` | high | too-few |
| `sensitive-data.jwt-token` | medium | too-few |
| `sensitive-data.private-key` | high | too-few |
| `test-quality.data-provider-annotation` | high | insufficient |
| `test-quality.empty-data-provider` | high | insufficient |
| `test-quality.mocking-domain-object` | low | unmeasured |
| `test-quality.naming-consistency` | high | insufficient |
| `test-quality.phpunit-coverage-source-missing` | medium | unmeasured |
| `test-quality.phpunit-deprecations-not-fatal` | high | unmeasured |
| `test-quality.phpunit-strict-flags-missing` | high | unmeasured |
| `test-quality.private-reflection` | high | insufficient |
| `test-quality.static-analysis-redundant-test` | high | unmeasured |
| `test-quality.trivial-snapshot` | medium | unmeasured |
| `waste.empty-method` | high | insufficient |
| `waste.unused-import` | high | insufficient |

## Summary By Pillar

| Pillar | Rules |
| --- | ---: |
| `complexity` | 4 |
| `dead-code` | 6 |
| `documentation` | 15 |
| `maintainability` | 1 |
| `modernisation` | 2 |
| `naming` | 6 |
| `security` | 18 |
| `sensitive-data` | 7 |
| `size` | 6 |
| `test-quality` | 18 |

## Rule Catalogue

### `complexity` (4)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `complexity.cognitive` | Cognitive complexity | `error` | `low` | yes |
| `complexity.cyclomatic` | Cyclomatic complexity | `warning` | `medium` | yes |
| `complexity.halstead-volume` | Halstead volume | `advisory` | `medium` | no |
| `complexity.nesting-depth` | Maximum nesting depth | `error` | `high` | yes |

`complexity.cyclomatic` and `complexity.cognitive` keep their raw metric values
in metadata, while flat validation flows made of top-level early-exit guard
clauses (`return`, `throw`, or `exit`) soften to advisory severity when they
cross the threshold. Nested decision trees, loops, switch/match sprawl,
try/catch control flow, and mixed-responsibility methods keep their configured
warning/error severity at 1.5x the limit or above; below 1.5x, cyclomatic,
cognitive, and nesting-depth findings report as lower-band advisory notices (see
**Two bands** under `size`). Upper-band complexity advice asks for a simpler
execution path rather than extracting helper methods.

- `complexity.cyclomatic` counts each `switch` and `match` as one decision,
  regardless of case or arm count.
- `complexity.cognitive` adds no nesting penalty for an early-exit guard (an
  `if` with no `elseif`/`else` whose body is a single `return`, `continue`,
  `break`, or `throw`) and scores `switch` case bodies at the switch's level.
- `complexity.halstead-volume` is off by default since 0.6.0; enable it with
  `rules.complexity.halstead-volume.enabled: true`.

### `dead-code` (6)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `dead-code.unused-private-constant` | Unused private constant | `warning` | `high` | yes |
| `dead-code.unused-private-method` | Unused private method | `warning` | `high` | no |
| `waste.commented-out-code` | Commented-out code | `advisory` | `medium` | yes |
| `waste.empty-method` | Empty method | `advisory` | `high` | yes |
| `waste.unreachable-code` | Unreachable code | `warning` | `high` | yes |
| `waste.unused-import` | Unused import | `warning` | `high` | yes |

`dead-code.unused-private-method` is off by default since 0.6.0 (ADR-034): the latest precision measurement judged it right on
4 of 38 findings, too few to establish its floor. Set `rules.dead-code.unused-private-method.enabled: true` to run it.

`dead-code.unused-private-method` counts callable references as real
usage when the method name is statically visible: `[$this, 'method']`,
`[self::class, 'method']`, `[ClassName::class, 'method']`, and PHP 8.1
first-class callable syntax such as `self::method(...)`. Dynamic
callable names remain conservative and do not mark every private method
as used.

`waste.commented-out-code` no longer reports a comment that explains code rather than disabling it.
A line that reads as prose, with two plain words in a row or a word before a literal (`returns 09`), scores nothing, and so do a
no-op `$v = $v;` and an example ending in `...`; a trailing `// note` on a disabled line is ignored, so that line still reports.
A comment that restates the assignment directly below it in another form also stays quiet: it must assign the same variable, use at
least two variables that all appear below, call nothing the line lacks, and carry an operator or keyword the line lacks, such as the
branchy `if` version of branchless arithmetic. An older version of the line below has no such operator and still reports.

### `documentation` (15)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `docs.bare-phpdoc-tags` | Bare PHPDoc tags | `advisory` | `high` | yes |
| `docs.missing-class-phpdoc` | Missing class PHPDoc | `advisory` | `high` | yes |
| `docs.missing-constant-phpdoc` | Missing constant PHPDoc | `advisory` | `medium` | yes |
| `docs.missing-file-phpdoc` | Missing file PHPDoc | `advisory` | `medium` | yes |
| `docs.missing-param-tag` | Missing @param tag | `advisory` | `high` | yes |
| `docs.missing-phpdoc` | Missing method PHPDoc | `error` | `high` | yes |
| `docs.missing-property-phpdoc` | Missing property PHPDoc | `advisory` | `high` | yes |
| `docs.missing-readme` | Missing README | `warning` | `high` | yes |
| `docs.missing-return-tag` | Missing @return tag | `advisory` | `high` | yes |
| `docs.missing-throws-tag` | Missing @throws tag | `advisory` | `medium` | yes |
| `docs.param-comment` | Described param tag | `advisory` | `high` | yes |
| `docs.regex-comment` | Regex comment | `advisory` | `medium` | yes |
| `docs.return-comment` | Described return tag | `advisory` | `high` | yes |
| `docs.todo-density` | TODO/FIXME density | `error` | `high` | yes |
| `docs.var-annotation-description` | Var annotation description | `warning` | `high` | yes |

`docs.missing-constant-phpdoc` distinguishes missing documentation from
useful local documentation. By default, constants may use an immediately
preceding meaningful `//`, `#`, or block comment when the prose is useful
to a human reviewer and attached directly to the constant. A PHPDoc or
meaningful comment above the first constant of an unbroken run covers
every constant in the run, whatever words it uses. A blank line or any
other statement ends the run; a change of visibility does not. When
another constant in the run has a comment of its own, each comment covers
only its own constant. Missing comments, detached comments, generic
comments such as `// constant`, and comments that only duplicate the
constant name still fire.

Projects that publish constants as API documentation can opt back into
PHPDoc for public/protected constants with
`requirePhpdocForApiConstants: true`, or only for exported paths with
`apiPathPatterns`. In that mode each exported constant needs a PHPDoc of
its own; one covered only by its run's comment reports with
`groupedLocalComment: true`.

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

`docs.missing-property-phpdoc` accepts a docblock above the first property
of an unbroken run, with no blank line or other statement between the
properties, as documentation for every property in the run. When another
property in the run has a docblock of its own, each docblock covers only
its own property. A physically attached `//` or `#` comment documents a
property only when `options.acceptLineComments` is true; it must carry
meaning beyond what the property name already says, and it never covers a
run. The default is false, so a docblock is required.

### `maintainability` (1)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `complexity.maintainability-index` | Maintainability index | `advisory` | `low` | no |

`complexity.maintainability-index` is off by default since 0.6.0 (right on 8 of
14 judged findings on unseen code). Unlike `complexity.cyclomatic`, it keeps the
full cyclomatic branch count. When enabled (`rules.complexity.maintainability-index.enabled: true`),
it skips test, fixture, and example files (directories named `test`, `tests`,
`__tests__`, `spec`, `testdata`, `fixtures`, or `examples`, and `*Test.php`
files).

### `modernisation` (2)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `modernisation.public-property` | Public mutable property | `warning` | `high` | yes |
| `modernisation.readonly-property-candidate` | Readonly property candidate | `advisory` | `medium` | yes |

`modernisation.enum-candidate` was retired in 0.4.1. Constant-only
scalar classes are not a default gruff rubric because safe enum
migrations require consumer-boundary audits across serialization,
templates, JavaScript/TypeScript, telemetry, JSON, and agent/runtime
interfaces.

`modernisation.public-property` covers promoted constructor properties as
well as declared ones, so upgrading can surface findings in constructors
that were never edited. Readonly promotions stay quiet. Name a fully
qualified class in `options.allowedClasses` when its mutable public state
is a deliberate lifecycle or integration contract.

### `naming` (6)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `naming.abbreviation-allowlist` | Abbreviation allowlist | `advisory` | `high` | yes |
| `naming.class-file-mismatch` | Class/file name mismatch | `warning` | `medium` | yes |
| `naming.confusing-name` | Confusing standalone class name | `advisory` | `medium` | yes |
| `naming.identifier-quality` | Identifier quality | `advisory` | `low` | yes |
| `naming.short-variable` | Short variable name | `advisory` | `high` | yes |
| `naming.test-naming-consistency` | Test method naming consistency | `advisory` | `high` | yes |

`naming.abbreviation-allowlist` keeps short lowercase abbreviations as findings
until the project accepts them through `allowlists.acceptedAbbreviations`.
Common two- and three-letter English words (such as `fee`, `row`, `tax`, `day`,
`end`, `map`, `sum`, `tag`, `zip`) and universal technical terms (such as `api`,
`csv`, `env`, `ids`, `jwt`, `pdf`, `sql`, `xml`) stay quiet out of the box and
remain disjoint from the sixteen-word `init` seed so replace-not-merge
configuration stays observable. Domain and architectural abbreviations such as
`dob`, `dto`, and `utc` still report until added to
`allowlists.acceptedAbbreviations`, while four-character `uuid` lies outside the
default two-to-three-character band. These findings carry `CONSIDER` plus the
full configuration key.

`naming.identifier-quality` applies its `loopBodyThreshold` escape hatch
to inline iteration callbacks as well as `foreach` loops: the sole
parameter of a closure or arrow function passed directly to an
array-iteration callable (`array_filter`, `array_map`, `array_walk`,
`usort`, `uasort`, `array_reduce`, `array_any`, `array_all`,
`array_find`) is treated as a loop variable, so a short name like
`$item` stays quiet while the callback body is below the threshold.
Longer callback bodies and generic parameters on non-iteration closures
still report.

### `security` (18)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `security.dangerous-function-call` | Dangerous function calls | `warning` | `medium` | no |
| `security.debug-mode-enabled` | Debug error display enabled | `warning` | `medium` | no |
| `security.dependency-composer-path` | Composer path repository | `warning` | `medium` | no |
| `security.dependency-composer-script` | Composer install-time shell script | `warning` | `medium` | yes |
| `security.dependency-composer-vcs` | Composer VCS repository | `warning` | `medium` | no |
| `security.disabled-ssl-verification` | Disabled SSL verification | `warning` | `high` | no |
| `security.error-suppression` | Error suppression operator | `warning` | `medium` | yes |
| `security.extract-compact-user-input` | extract or compact on request data | `warning` | `medium` | yes |
| `security.header-injection` | Header injection risk | `warning` | `medium` | no |
| `security.permissive-cors` | Permissive CORS with credentials | `warning` | `medium` | yes |
| `security.process-command-construction` | Process command construction | `warning` | `medium` | yes |
| `security.reflected-xss` | Reflected XSS sink | `warning` | `medium` | yes |
| `security.request-controlled-url` | Request-controlled URL | `warning` | `medium` | yes |
| `security.silent-catch` | Silent catch block | `warning` | `high` | no |
| `security.sql-concatenation` | SQL string concatenation | `warning` | `medium` | no |
| `security.unsafe-archive-extraction` | Unsafe archive extraction | `warning` | `medium` | yes |
| `security.unsafe-unserialize` | Unsafe unserialize usage | `warning` | `medium` | no |
| `security.unsafe-xml-loading` | Unsafe XML loading | `warning` | `medium` | yes |

Nine security rules are off by default since 0.6.0 (ADR-034) because precision
measurements either left too few findings to establish a floor or fell short of
the 0.80 floor. Enable any of them with `rules.<id>.enabled: true`:

- Too few judged findings to establish a floor: `security.dangerous-function-call`
  (3/6), `security.disabled-ssl-verification` (1/1, rest unresolved),
  `security.sql-concatenation` (4/11), `security.unsafe-unserialize` (2/8),
  `security.dependency-composer-path` (0/2), `security.dependency-composer-vcs`
  (0/2 actionable), `security.debug-mode-enabled` (3 remaining after repair),
  and `security.header-injection` (9 remaining after repair).
- Below the 0.80 floor after repair: `security.silent-catch` (13/25).

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
An operand escaped where it is spliced also passes: an `(int)` cast, or
phpMyAdmin's `->quoteString()` value escaper or `Util::backquote()`
identifier escaper, both matched by name. A concatenation inside their
arguments is not checked. `quoteString()` and `backquote()` count only
outside an already open quote, because they supply their own, and none of
the three counts in a construction passed through another call such as
`sprintf()`, which can splice other values around it. Any other escaping
helper still flags.

`security.process-command-construction` treats an `escapeshellarg()`
operand and an `(int)` cast as escaped, including a concatenation inside
`escapeshellarg()`'s own argument, so only a bare dynamic value spliced
into the command reports. `escapeshellarg()` counts only outside an open
shell quote, and a construction passed through another call such as
`sprintf()` always reports. `escapeshellcmd()` is not an escaper here,
because it leaves argument separators in place.
A local the function only ever sets to plain words, such as `$mode = $isDirectory ? 'J' : 'H'`, also passes.
A word may hold letters, digits and `_ . / : = + -`; a space, quote or backslash reports, and so does a local passed to a call, written
by `extract()`, `parse_str()`, `eval` or an include, or bound any way other than a plain assignment.

`security.reflected-xss` counts WordPress's `esc_html()`, `esc_attr()`
and `esc_url()` as HTML escapers alongside `htmlspecialchars()`, `e()`,
URL encoders and numeric casts. `esc_url_raw()` still reports, because it
does not encode for HTML output.
Four more shapes carry no markup and stay quiet:
- a request value inside `isset()` or `empty()`;
- the number argument of `absint()`, `number_format()` or `number_format_i18n()` (a tainted separator argument still reports);
- `json_encode()` output after an earlier `header('Content-Type: application/json')` beside the output, unless a later header replaces it;
- output after a top-level `if ('cli' !== PHP_SAPI) { throw ...; }` guard, or the same guard with `php_sapi_name()`, `exit` or `return`.

`security.debug-mode-enabled` no longer reports a toggle that a web visitor cannot reach.
That is a branch reached only after `!in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'])` failed, or the body of `if (SOME_CONSTANT)` when
the file does not set that constant itself, such as WordPress's `WP_DEBUG_DISPLAY`. A negated constant, an `else`, `if (true)`, a
request check and a closure inside the guard still report.

`security.header-injection` no longer reports two values that cannot carry CR/LF.
One is a status line built only from `$_SERVER['SERVER_PROTOCOL']` and literal text starting with a status code, such as a hand-written 404.
The other is a value whose dynamic parts are all integers: an `(int)` cast, an integer literal, or a local only ever assigned one.
Any other write to that local reports, including `.=`, a loop or destructuring binding, a reference, `extract()`, `eval` or an include.

`security.silent-catch` no longer reports an empty catch whose comment gives a reason, or whose caught exception the code reads afterwards.
A reason needs a word such as `if`, `because`, `must`, `cannot` or `fall back` plus a word of its own, so `// do nothing`,
`// Catch exceptions and remain silent.`, a TODO and commented-out code still report. A later read is the first use of the caught
variable after the try, such as `if ($error !== null) { log($error); }`; a new binding, `unset()` or `isset()` first still reports.

`security.dangerous-function-call` trusts a dynamic call only when PHP syntax or
local flow proves the callee is a safe callable:

- **Proved callables:** a closure, arrow function, `new` object of a written
  class, first-class callable of a written method (other than reflection's
  `invoke(...)`) or of a PHP built-in that accepts no callable (`strlen(...)`,
  never `array_map(...)`, `call_user_func(...)`, or an opaque userland function),
  a `callable` or `Closure` type hint or `@param`/`@var` docblock (`callable-string`
  proves nothing), an enclosing `instanceof` or one-argument `is_callable()` check,
  a callable-typed class property, or an immediately invoked closure.
- **Scope and taint:** proofs hold only inside the declaring function (plus
  non-shadowing arrow functions and closures that `use` the variable). Request
  input overrides every proof: a callee filled from a superglobal always reports
  unless last bound to a closure literal. `Closure::fromCallable($x)()` is
  judged as `$x()`, and `$f(...)` builds a `Closure` without calling it.
- **Literals, aliases, and callback buckets:** a local written once with one
  plain string literal on the same branch is judged as a direct call to that
  function (`$f = 'strtoupper'; $f($s)` stays quiet; `$f = 'system'; $f($c)`
  reports as `system`), unless by-reference capture, `extract()`, one-argument
  `parse_str()`, `eval`, `include`, or `goto` appears in the function. Loops over
  a local array of closure literals, bounded local aliases (up to eight steps and
  resolved `Closure::bind()`, stopping at ambiguous branches, reassignment,
  borrowed references, unknown receivers, or coalescing with an unproved branch),
  and callbacks returned from one visible same-class method reading an initially
  empty non-public typed-callback property (keyed lookup with empty fallback or
  `array_merge()` with positional arguments) stay quiet.
- **Execution calls and configuration:** built-in execution functions (`exec`,
  `passthru`, `popen`, `proc_open`, `shell_exec`, `system`) with one plain string
  literal command stay quiet; any dynamic command and every name in
  `options.additionalFunctions` (appended case-insensitively to the built-in
  list, never replacing it) still report. When `security.process-command-construction`
  reports the same `exec`-family call, it reports once under that rule and logs a
  `duplicate` row in `suppressions` (FAMILY-CONTRACT.md section 13c).

Security rules that read an argument from a global function resolve it by
the parameter name PHP declares, so `header(header: $target)` is analysed
exactly like `header($target)`. Sinks reached through a method or
constructor call - `$pdo->query()`, `$zip->extractTo()`, `new Process()` -
still match by position only, because their parameter names belong to the
library rather than to PHP.

### `sensitive-data` (7)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `sensitive-data.api-key-pattern` | Common API key pattern | `warning` | `high` | yes |
| `sensitive-data.aws-access-key` | AWS access key | `warning` | `high` | yes |
| `sensitive-data.database-url-password` | Database URL password | `warning` | `high` | no |
| `sensitive-data.gcp-service-account-key` | GCP service-account key | `warning` | `high` | yes |
| `sensitive-data.jwt-token` | JWT token literal | `warning` | `medium` | yes |
| `sensitive-data.pii-test-fixture` | PII in test fixture | `warning` | `medium` | no |
| `sensitive-data.private-key` | Private key material | `warning` | `high` | yes |

`sensitive-data.database-url-password` is off by default since 0.6.0: the 0.6.0
precision measurement found it wrong on all nine of its judged findings, too few
to delete on (ADR-034). Set `rules.sensitive-data.database-url-password.enabled:
true` to run it.

Across the secret rules, a placeholder word such as `test` or `example`
suppresses a value only when it begins a token, so `latest` and `attestation`
still report.

`sensitive-data.pii-test-fixture` now accepts two fixture shapes its
remediation already recommends: emails whose domain ends in a reserved
special-use TLD (`.local`, `.test`, `.invalid`, `.localhost`,
`.example`, matched at a label boundary), and addresses whose matched
tokens or surrounding line carry a synthetic marker word (`test`,
`fake`, `sample`, `demo`, `anytown`, matched as whole words). Realistic
emails, addresses without a marker, and phone numbers outside the
555-010x reserved block still flag.
It is off by default since 0.6.0 (ADR-034): after its repair it was judged right on 35 of 47 findings, below its 0.80 floor.
Set `rules.sensitive-data.pii-test-fixture.enabled: true` to run it. It also skips three email-shaped values that are not mailboxes: URL userinfo such as `http://user:password@host`, the value that opens
an HTTP auth `realm="..."`, and a local part that repeats its domain's name, such as `foo@foo.com`. A `mailto:` address still reports.

### `size` (6)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
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

**Two bands.** Every `size.*` and `complexity.*` rule except
`complexity.halstead-volume` and
`complexity.maintainability-index` reports in one of two bands
(FAMILY-CONTRACT.md section 12, "Size and complexity findings in two bands").
A unit over its limit but under one and a half times it is an advisory
notice, whatever the configured tier, with `limitBand: lower` in its metadata
and advice not to add to it. At one and a half times the limit or more the
finding keeps its severity, including the read-only data-carrier and
flat-guard softening above, carries `limitBand: upper`, and asks you to split
the unit or simplify its execution path. The limit is the lowest configured
tier, or the unit's own limit where it has one: the promoted value-object
ceiling, or the constructor cap when `constructorMaxParameters` is set.
Messages keep naming the tier the value crossed, so a baseline entry keeps
matching when a unit crosses the boundary. A `--fail-on warning` or
`--fail-on error` gate no longer fails on a lower-band notice; the default
`analyse` gate, `advisory`, still does. Size and complexity rules only ever
measure `.php` source; configuration and data files never reach them.

### `test-quality` (18)

| Rule ID | Name | Severity | Confidence | Enabled By Default |
| --- | --- | --- | --- | --- |
| `test-quality.data-provider-annotation` | Data provider annotation | `advisory` | `high` | yes |
| `test-quality.empty-data-provider` | Empty data provider | `error` | `high` | yes |
| `test-quality.exception-type-only` | Exception type-only assertion | `advisory` | `medium` | yes |
| `test-quality.global-state-mutation` | Global state mutation in test | `warning` | `medium` | yes |
| `test-quality.loop-assertion-without-message` | Assertion in loop without message | `advisory` | `medium` | no |
| `test-quality.mocking-domain-object` | Mocking a domain object | `advisory` | `low` | yes |
| `test-quality.naming-consistency` | Test naming consistency | `advisory` | `high` | yes |
| `test-quality.no-assertions` | Test without assertions | `error` | `medium` | no |
| `test-quality.phpunit-coverage-source-missing` | PHPUnit coverage source missing | `advisory` | `medium` | yes |
| `test-quality.phpunit-deprecations-not-fatal` | PHPUnit deprecations not fatal | `warning` | `high` | yes |
| `test-quality.phpunit-strict-flags-missing` | PHPUnit strict flags missing | `warning` | `high` | yes |
| `test-quality.private-reflection` | Private member reflection | `warning` | `high` | yes |
| `test-quality.repeated-structure-missing-data-provider` | Repeated test structure missing data provider | `advisory` | `low` | yes |
| `test-quality.static-analysis-redundant-test` | Static-analysis-redundant test candidate | `advisory` | `high` | yes |
| `test-quality.sut-not-called` | Test name mentions SUT that is not called | `advisory` | `low` | no |
| `test-quality.test-longer-than-sut` | Test longer than apparent SUT | `advisory` | `low` | no |
| `test-quality.testdox-readability` | Testdox readability | `advisory` | `low` | yes |
| `test-quality.trivial-snapshot` | Trivial snapshot | `advisory` | `medium` | yes |

These two rules are off by default after calibration left them below the 70% test-quality precision floor: `test-quality.loop-assertion-without-message` and `test-quality.no-assertions`. Set the chosen rule's `rules.<rule-id>.enabled` to `true` to opt in.

`test-quality.repeated-structure-missing-data-provider` enables `rules.test-quality.repeated-structure-missing-data-provider.options.groupFindings` by default. Group mode reports one finding for at least three tests in the same class, each with at least two top-level statements. It compares complete bodies and requires matching attributes and annotation tags. Identifiers, calls, operators and statement order remain significant. Literal values are compared by kind, except strings containing ASCII whitespace and literal array keys or indices, which must match exactly. A declaration anywhere in a body, including an anonymous class, retains its literals and PHPDoc; attribute arguments stay exact even on a closure. Ordinary closure and local static values still normalize. Tests already driven by a PHPUnit provider, inline case attribute or literal table loop are excluded. Each finding lists every member and declaration line, with a qualified-method-name-to-line mapping in `metadata.members`, and recommends a provider with descriptive case labels. Set the option to `false` to retain legacy shape-group findings.

`test-quality.sut-not-called` and `test-quality.test-longer-than-sut` are also opt-in because their frozen cards leave precision insufficient (28 and 50 unresolved cards out of 50). This is an operator policy decision, not a measured floor failure. Enable either with `rules.<rule-id>.enabled: true`.

`test-quality.no-assertions` follows invoked methods on the same class, up to eight method bodies; unused helpers and callback references do not count.
It also recognizes `expectDeprecationWithIdentifier` from the resolved Doctrine `VerifyDeprecations` trait when no local override or trait adaptation changes it.
External helpers, unresolved receivers and cycles without a recognized check still report. An assertion-like helper name alone supplies no evidence.

`test-quality.no-assertions` also accepts Symfony user-deprecation message expectations, registered Prophecy terminal expectations and a local receiver created by `Illuminate\Testing\Fluent\AssertableJson::fromArray` with `where` or `whereContains`. Unused callbacks, first-class callable references, foreign builders and rebound receivers do not establish that fluent expectation.

In `test-quality.mocking-domain-object`, PHPUnit `createStub()` supplies values
and does not count as a mock.

`test-quality.loop-assertion-without-message` accepts a literal singleton, a direct assertion followed by an exit, or an expected value that identifies the loop binding. In a keyed table the key identifies the row; its value alone may repeat.

`test-quality.sut-not-called` is advisory and compares visible calls with a verb-and-object prefix from the test name. A lone verb is insufficient evidence of the intended subject.
