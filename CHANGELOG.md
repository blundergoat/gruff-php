# Changelog

Notable user-facing changes to `gruff-php` are listed here.

## 0.6.0 - Unreleased

Upgrading from 0.5.x: this release changes every machine-readable contract at once. `UPGRADING.md` in this repository gives each break's migration command and the way back: pin the 0.5 line and keep the pre-upgrade configuration and baseline files, which 0.5 still reads.

- **`sensitive-data.high-entropy-string` skips package-manager lockfiles by name** - A lockfile records one published integrity digest per resolved package. Every one of them is high-entropy by construction and none is a credential, so a real project's lockfile buried the rule's true findings under thousands of false ones. The rule, and no other, is now skipped in a file whose base name is one of nine ratified lockfile names at any depth: `package-lock.json`, `npm-shrinkwrap.json`, `yarn.lock`, `pnpm-lock.yaml`, `composer.lock`, `Cargo.lock`, `go.sum`, `uv.lock`, `poetry.lock`. **The skip is counted, never silent:** every surface that applies it publishes one audit row per lockfile that had findings, carrying `source: "built-in"`, which is how a consumer tells it from an entry you configured. **Every other sensitive-data rule still reads the file**, so a credential pasted into a lockfile is reported exactly as it would be anywhere else, and the identical bytes under any other file name keep reporting the entropy rule too.
- **A scope the run could not read is one diagnostic, `changed-region`, with no findings beside it** - A `--changed-ranges` value the run cannot scope to arrived on the hook as `usage-error`, a different name from the one analyse used, and some published their unscoped findings alongside it, so a caller could not tell a narrowed scan from a whole-tree one. The run now exits `2` with exactly one `changed-region` diagnostic and no findings, on the analyse surface and the hook alike.
- **BREAKING: an empty `--changed-ranges` is refused instead of scanning everything** - `--changed-ranges=` asks for a scoped run and names no range, and it was read as "no filter": the run silently widened to the whole tree and exited `0`. It now exits `2` with the same `changed-region` diagnostic, on analyse and on the hook. A string flag cannot tell an absent flag from one passed empty, which is why the option now records that it was given. The hazard is worse on the hook, where a silently widened scope hands an agent a whole-tree finding list attributed to the edit it just made. The shipped `.goat-flow/hooks/gruff-code-quality.sh` wrapper guards the value before invoking the binary, so no managed hook could produce this input.
- **A cached `analyse` reports what `summary` and `analyse --no-cache` report** - The result cache keyed on the tool version and the resolved rule set, but not on the analyser's own source, so editing a rule left stale findings served from cache while `summary`, which does not read the cache, reported the current ones. Measured on this repository before the repair: `analyse` served 3,255 findings where `summary` and `analyse --no-cache` both reported 3,203. The key now folds in the path and bytes of every PHP file under the analyser root, so an edited rule or helper invalidates it even when the version string has not moved. This costs one digest per run: 289 files and about 3.1 MB, a median 2.1 ms.
- **A masked AWS key no longer reports** - A key whose sixteen-character body is entirely `X`, written to show where a key goes, reported as `sensitive-data.aws-access-key` under both the `AKIA` and the `ASIA` prefix. FAMILY-CONTRACT.md section 5 reads such a value as naming no credential, so it is now quiet. Only the whole body counts: a real key that merely contains a run of `X` still reports, because hiding it would hide a live credential.
- **Fixed: a configuration error publishes its envelope when the target is outside the launch directory** - `analyse ../proj --format json` from a sibling directory, with a `--config` that could not load, exited `1` with an uncaught `Machine path "../proj" is outside the project root` and printed nothing on stdout, because the failure envelope is rooted at the launch directory and the target has no path under it. The envelope now leaves that input out, as it already left out the config path, and the run exits `2` with its `config-error` diagnostic.
- **Fixed: results no longer depend on the launch directory** - `summary ../proj --format json` from a sibling directory exited `1` with an uncaught `Machine path ... is outside the project root` and printed nothing on stdout, because `summary` rooted the run at the launch directory. And from one of the project's own subdirectories, `analyse ..`, `summary ..` and `report ..` read the operand against the project root instead of the launch directory, scanned the directory above the project, and exited `1` the same way. `summary` now takes the project root from its targets, as `analyse` does, and every command reads each operand from the launch directory, so they give the same result as a run from inside the project.
- **Fixed: a relative `--config` path is read from the launch directory** - An explicit `--config` path means what was typed, relative to the directory the command runs in, as a scan target is. gruff-php read it against the project root the targets resolved to, so `analyse ../.. --config ../../cfg.yaml` from two levels inside the project looked for a file outside it and refused the run with a config error. gruff-go and gruff-py already read it this way. A discovered `.gruff-php.yaml` is still looked up at the project root.
- **Fixed: a relative `--baseline` or `--generate-baseline` path is read from the launch directory** - A baseline path means what was typed, relative to the directory the command runs in, as a scan target and `--config` are. gruff-php read both against the project root, so from two levels inside the project `--generate-baseline ../../base.json` wrote the file two levels above the project, and `--baseline ../../base.json` exited `1` with an uncaught exception and nothing on stdout. A diagnostic about a file outside the project now leaves out its `file` key instead of failing the report.
- **`sensitive-data.high-entropy-string` skips public PEM blocks** - Text between `-----BEGIN <label>-----` and its matching `-----END <label>-----` no longer reports when the label names no private key: a certificate, public key, certificate request, PKCS7 bundle or CRL is public by construction, as FAMILY-CONTRACT section 12 now states. A private key's block is still scanned. No file in the family's corpus hit it in gruff-php, which scans only whole quoted literals.
- **Fixed: the text `summary` exits with the run's exit code** - It ended every run with `0`, so a project whose scan a parse error invalidated printed a normal digest and passed a CI gate, while `summary --format json` and `analyse` exited `2` for the same run. The text digest now exits `2` there too, and `0` for a clean run as before.
- **`sensitive-data.high-entropy-string` needs a letter and a digit** - A literal must hold at least one letter and one digit, the floor FAMILY-CONTRACT section 12 now sets for all five ports. A run of one character class clears the entropy bar by construction, and a digit-free mix of cases is an identifier. Measured on the family's 57-repository corpus, gruff-php's entropy findings fall from 326 to 291, among them OOXML and Symfony MIME types.
- **`waste.one-line-method` now says `CONSIDER`, never `APPLY`** - A method implementing an interface declared in another file looked like a wrapper, and inlining it is a fatal error. `{@inheritdoc}`, `#[\Override]`, a same-file documented ancestor, an override whose body is `parent::sameName()`, and a call only inside an assignment's left-hand subscript are no longer reported. An `@inheritdoc` marker exempts a method only in a class-like that can inherit a contract: a class that extends, implements, or uses a trait, an enum that implements or uses a trait, or a trait.
- **`security.dangerous-function-call` proves callability the way PHP does** - An immediately invoked closure, a `new` object, `Closure::fromCallable($x)()` judged as `$x()`, an enclosing `instanceof` or `is_callable()` test, a `@param callable` or inline `@var callable` docblock, closure and arrow-function parameters, and `$f(...)` no longer report. Trust is now scoped to the function that proves it, so a `callable $x` parameter in one function no longer hides `$x()` in another, and no proof outranks request input: a callee filled from a superglobal, `new $class` with a caller-chosen class, a first-class callable of a function that calls its callable argument such as `call_user_func(...)` or `array_map(...)`, of a userland function, or of reflection's `invoke(...)`, a `callable-string` docblock and a property read through a request-derived object are reported; a closure literal stays trusted whatever its body reads. Expect some real findings to reappear.
- **`modernisation.named-argument-opportunity` skips variadic built-ins and dynamic callees** - `sprintf()`, `printf()`, `pack()`, `array_merge()`, `compact()` and other PHP variadics cannot name their extra arguments; naming them threw `ArgumentCountError`. A namespaced function that shares a built-in's short name, such as `\App\sprintf()`, is still advised.
- **`sensitive-data.high-entropy-string` reads only the configured thresholds** - A 64-character hex literal no longer reports through an override that ignored `entropy`, and a pure-hex literal is skipped at any bar, as in gruff-go. `minLength` below 32 now widens the scan instead of doing nothing, down to the shortest literal that can reach the `entropy` bar.
- **Placeholder words must begin a token** - The seven secret rules suppressed any value containing `test`, `example` and six other words, so `latest` and `attestation` hid real values. A word now counts only at the start of a token. AWS's documented example key now reports under `sensitive-data.aws-access-key`, which also matches whole alphanumeric runs, as in gruff-go.
- **`docs.missing-return-tag` and `docs.missing-param-tag` honour inherited contracts** - An override owes no tag its inherited contract already declares. When the overridden method is in the same file, only what it declares counts: its `@return`, and each `@param` at the same position, so a parameter the override adds is still owed. When it is in another file, `{@inheritdoc}` or `#[\Override]` stands in for it, but only in a class-like that can inherit. Constructors never inherit. The return-tag message now asks only for the tag.
- **Inheritance markers are read more strictly** - A sentence that merely mentions `@inheritdoc` is no longer a marker, and ancestors and `#[Override]` are matched by their resolved names, so a same-named class in another namespace of the file no longer stands in for the real parent. This also applies to `docs.missing-throws-tag` and to `waste.one-line-method`'s `@inheritdoc` exemption.
- **A symbol containing `#` no longer breaks the baseline** - Such a finding is reported with no identity, like a sensitive finding, instead of aborting baseline generation, baseline application and SARIF output.
- **A v1 baseline refusal points at a route that works** - It no longer advises `--migrate-baseline`, which refuses v1 files; it says the reviews cannot be carried forward and prints `gruff-php analyse --generate-baseline <path> --force`, which regenerates the file in place.

- **BREAKING: `list-rules --format json` publishes thresholds as a named knob map** - A tunable rule's `thresholds` is now `{"maxLines": 100}` or `{"threshold": 10}`, never the `{"threshold": N, "severity": S}` pair; the severity is already the row's `defaultSeverity`. A rule with no threshold omits `thresholds` instead of publishing `{}`. `list-rules <ruleId> --format json` changes the same way. `.gruff-php.yaml` keys are unchanged; this is the shape all five ports publish at 0.6.0.
- **BREAKING: the per-command exit gate moves from `minimumSeverity:` to `failOn:`** - A config carrying the per-command `minimumSeverity:` map is refused with exit `2`, naming `failOn` as the key that gates the exit code. `failOn` accepts `analyse`, `report` and `dashboard`; `minimumSeverity` survives as a scalar display floor that hides findings below one severity without changing the exit code or the score, and it takes `advisory`, `warning` or `error` rather than `none`. The split is the ratified family contract (`gruff-spec/contracts/core/cli.v1.json`, ratified 2026-09-06), chosen so a configuration can never silently gate. `gruff-php migrate-config` renames the key and preserves the original file.
- **BREAKING: the agent-hook contract moves from `gruff.hook.v1` to `gruff.hook.v2`** - The payload's `contractVersion` changes, and the envelope gains two required keys: `run`, carrying the audit data a consumer needs to trust the verdict (mode, scope, operands, `analysedFiles`, and the applied baseline), and `suppressions`, one row per configured sensitive exclusion the run applied. The exits are ratified as three and no others: `0` when nothing reached the gate, `1` when something did under an explicit consumer request (`--fail-on`, `--fail-on-new`, or `--fail-on-diagnostics`), and `2` when the run could not happen. A consumer reading the v1 seven-key payload keeps working; one that validates the key set must accept the two new keys. The contract is `gruff-spec/contracts/core/hook.v2.json`, ratified 2026-09-06.
- **BREAKING: baselines move to the family `gruff.baseline.v3` file, and every finding identity changes once** - A baseline row now stores one line-free identity and a count: sha256 over the tool language, native rule id, project-relative path, and a subject that is the symbol plus its declaration ordinal, or, when no symbol is named, the message with its measured values normalised so a file that grows keeps its reviewed identity. The message no longer enters a symbol-bearing finding's identity, so rewording a rule's message no longer expires a reviewed entry; two methods of one name in one file can no longer share a review; and a second occurrence beyond the reviewed count is reported as new rather than hidden. A `gruff.baseline.v2` file fails closed and names the migration command.
- **`analyse --migrate-baseline <old> --generate-baseline <new>` carries a 0.5 baseline forward** - The reviewed findings are re-identified from the current scan and written to a separate file; the original is never written to, renamed, or deleted, and an output that is the input by spelling, symlink, or hard link is refused. A 0.5 file naming more than one row container is refused too, because the five 0.5 writers used three container keys and such a file would migrate differently in different ports. A refused migration writes nothing and leaves the original byte for byte. `--migrate-baseline` without `--generate-baseline` exits 2.
- **BREAKING: sensitive-data findings can no longer be baselined** - A generated baseline counts them by rule under `sensitive.counts` and stores no row, path, or message for them, and a hand-written row cannot hide one: a secret stays visible and blocking until it is fixed or excluded with a reason under `sensitiveExclusions`.
- **A generate at the default path refuses to destroy a 0.5 baseline** - All five ports write and auto-discover gruff-baseline.json, so an ordinary upgrade-then-generate used to overwrite the 0.5 file that is the documented retreat path, before the user knew they needed it. A generate whose destination is that filename now refuses when the file there is not a v3 baseline, names the file and --force, and writes nothing. --force overwrites it, and regenerating v3 over v3 is unaffected.
- **One baseline section, read the same way in five ports** - The analysis envelope's `baseline` container now always carries `applied`, `entries`, `generated`, `newFindings`, `resolvedFindings`, `source`, `suppressedFindings`, `unchangedFindings` and `path`. A generate or migrate run compared against nothing, so its movement counts are zero rather than absent: a reader never has to tell a missing key from a zero. Applying a baseline still removes only reviewed findings from the score and the exit code; a collision and a sensitive finding count toward both.
- **`analyse` JSON gains `applied`, `unchangedFindings` and `resolvedFindings`** - The six-way split was published only under the php `buckets` extension, so a family consumer could not read what the baseline hid or what the user had since fixed.
- **BREAKING: SARIF `partialFingerprints.gruffFingerprint` is the ratified identity, and a secret carries none** - Code scanning grouped alerts by the line-bearing fingerprint, so an alert closed and reopened every time code moved above it. It is now the same durable identity baseline matching reads: every existing alert closes and reopens once at this break, and each one then survives an ordinary edit. Two same-named declarations in one file, previously one alert, become two. A sensitive finding publishes no `partialFingerprints` at all, so its alerts close and later occurrences arrive ungrouped; a secret must not be given a durable name in a system gruff does not control. Findings of one rule on one declaration, such as one per unused parameter, share that identity, so code scanning shows them as one alert.
- **BREAKING: SARIF results no longer carry `gruffStableIdentity`** - It was a php-only second line-free digest beside the fingerprint. The fingerprint is now itself line-free and ratified for the family, so the extra key published a different name for the same purpose.
- **Collisions are reported, never hidden** - When one identity covers two declarations, both findings stay in the report and a `baseline-collision` diagnostic names the identity, rule, path, and subjects. JSON `extensions.php.baseline.buckets` gains `collision`, `notEligible`, and `sensitiveCounted` beside `new`, `unchanged`, and `absent`.
- **A baseline written by another gruff port is refused** - The file records its `toolLanguage`; applying a file another port wrote reports a `baseline-error` instead of resolving every row and inviting a destructive regenerate.
- **BREAKING: every score changes - the family adopts one normalized scoring formula** - A pillar is now `floor + (100 - floor) / (1 + density / densityScale)`, where `density` is the pillar's summed severity-by-confidence weight divided by the number of PHP files that were evaluated. Scores no longer track project size: duplicating a project leaves its grade unchanged, where before it fell - a 4x duplication of identical code moved this port's composite from 79.70 to 70.00. The severity and confidence weights are unchanged, so the movement is the formula, not a re-weighting. Grade boundaries stay at A>=90, B>=80, C>=70, D>=60, because an even five-way split of the new range reproduces them exactly.
- **BREAKING: the composite can be null, and so can a pillar or file grade** - `score.composite.{score,grade}` are `null` when the run evaluated nothing at all: an empty directory, or one whose every PHP file failed to parse, previously reported a perfect `100` and grade `A`. `score.topOffenders[].{score,grade}` are nullable for the same reason. `summary --format text` renders `Composite: n/a (nothing evaluated)`, and so does `analyse --format text` when files were discovered and none could be evaluated; when nothing was discovered at all, `analyse --format text` prints no score line. Markdown renders `**Grade:** n/a (n/a/100)`, and HTML shows `not evaluated` in the grade stamp.
- **BREAKING: `score.pillars[].penalty` and `score.topOffenders[].penalty` are raw weights** - They were the summed weight multiplied by 4 for a pillar and 5 for a file; both multipliers belonged to the retired absolute-sum formula and are gone. A pillar that reported one high-confidence error now publishes `penalty: 12`, not `48`.
- **`score.evaluatedFiles` and `score.scoredPillars` are published** - The scoring denominator and the pillar set it was drawn from are now in the envelope, so any consumer can reproduce the composite. `evaluatedFiles` counts PHP files that survived discovery and parsed; it is deliberately not `summary.analysedFiles`, which also counts non-code inputs.
- **Per-file scores follow the same curve as the project** - `score.topOffenders[].score` is the ratified curve over that file's own weighted findings, so file ranking and project grading can no longer disagree about the same code.
- **A run that evaluated nothing records no trend point** - `--history-file` skips a scoreless run rather than writing a row every later delta would have to subtract from.
- **BREAKING: machine JSON adopts the family v3 envelopes** - Update analysis consumers to accept `gruff.analysis.v3`, use one `findings[].file` path, read `score.composite.{score,grade}`, move ignore evidence under `paths`, read changed-region counts from `summary.suppressedFindings` and `diff.filteredFindings`, and read PHP-only trend, mutation, and review data from `extensions.php.topLevel`. `summary --format json` now emits `gruff.summary.v3`, the analysis document with only top-level `findings` removed. Finding identities, score values, baseline matching, action metadata, and exit decisions are unchanged. This coordinated pre-1.0 family break has no v2 writer or deprecation window so every port releases one machine contract.
- **Every heuristic rule now documents where it misfires** - All 70 `medium` and `low` confidence rules carry `falsePositiveShapes`, each shape paired with the mitigation that answers it, so a surprising finding can be judged without reading the detector.
- **`list-rules --format=json` publishes that guidance in the catalogue** - The full-list rows carry `falsePositiveShapes` alongside the per-rule detail view, which already did. The key is present only for rules that catalogue guidance, so an absent key means none is catalogued rather than none exists.
- **BREAKING: default scans use the family fallback policy** - Non-VCS fallbacks now defer to any governing `.gitignore`, committed control metadata stays scannable, and explicit supported files bypass Git and fallback exclusions. PHP retains `.gruff-cache`, `.phpunit.cache`, and `var/cache`; eligible lockfiles are no longer dropped by filename, while VCS internals remain blocked.
- **Bounded deep scans protect large PHP inputs** - PHP source over 20,000 lines or 2,000,000 bytes keeps file-size, sensitive-data, and config-text rules while parsing, masking, AST walking, and other structural work are dropped.
- **Budget degradation is visible and nonfatal** - Every output surface emits `bounded-deep-scan` with the path, measured lines and bytes, both limits, and whether defaults, config, or CLI supplied them; the file still counts as analysed.
- **Config and CLI can tune or disable the guard** - Set `deepScanBudget.enabled`, `maxLines`, and `maxBytes`, override both limits atomically with `--deep-scan-budget <lines>:<bytes>`, or use `off`; CLI wins over config and non-PHP text is never guarded.
- **New `sensitiveExclusions:` config section** - Accept a reviewed sensitive-data finding by naming one rule id, one project-relative path, and a reason.
- **Exclusions are authored by hand** - No command converts a reported finding, message, or preview value into an entry; `gruff-php init` seeds the section empty with guidance.
- **An optional `symbol:` narrows an entry** - No sensitive-data rule stamps a symbol yet, so an entry carrying one matches nothing today and reports zero.
- **Invalid entries stop the run with exit 2** - Wildcards, pillar names, unknown or non-sensitive rules, absolute/`..`/globbed paths, a missing reason, and a duplicated scope are all rejected by entry index and key.
- **Message and value matching is rejected** - `message_contains`, `messageContains`, `value`, `preview`, and any other key fail, so a suppression can never be written against the secret itself.
- **JSON reports publish every suppression** - Analysis and summary v3 include one `{index, rule, paths, symbol?, reason, suppressed}` row per configured entry, present and empty when nothing is configured.
- **Text reports state the suppression total** - A `Suppressed findings: N via ...` line names each entry and its reason, so a hidden finding is a number rather than an absence.
- **Suppressed findings leave scoring and exit codes** - They are removed like accepted baseline debt; an entry matching nothing reports `suppressed: 0` instead of failing.
- **`summary` applies sensitive exclusions** - The digest filters, counts, and scores the same findings `analyse` does, so its totals no longer disagree with the report it digests.
- **`summary` publishes the full suppression audit** - Text prints the same `Suppressed findings: N via ...` line as `analyse`. JSON emits `gruff.summary.v3` with the same `suppressions` rows and `summary.suppressedFindings` count as the corresponding analysis run.
- **Known limitation: two detector fixes measured on 2026-08-12 have no family regression suite** - `security.sql-concatenation` and `security.process-command-construction` reporting procedural `mysqli_query` and `shell_exec` sinks, and `test-quality.mock-without-expectation` reading Prophecy expectations, ship unguarded by the family, because the suite that would hold them was deferred past 0.6.0.
- **The self-scan gate proves zero findings, not zero false positives** - `gruff-php` finding nothing in its own repository is an invariant this release holds, not evidence about false-positive rates, which negative fixtures measure and a self-scan cannot.

## 0.5.2 - 2026-08-16

0.5.2 counts only substantive lines in `size.file-length` and `size.class-length`, so blank and comment-only lines stop consuming a file's or class's budget, and it teaches the security rules to read named arguments, procedural SQL drivers, and `proc_open()` argument vectors. Prophecy expectations, promoted-property docs, and `summary`'s secret allowlist shed false positives, while public mutable promotions start reporting. Empty scans carry a non-fatal `empty-analysis` diagnostic and no score instead of a full-marks grade. Four rule options ship alongside self-documenting `init` output, and the agent hooks close four parser bypasses and a batch-boundary gap. No rule IDs, severities, thresholds, scoring, `gruff.analysis.v2`, or `--fail-on` behaviour changed; both size rules emit new identities and several rules gain rows, so the baseline impact is noted below.

- **File and class lengths count substantive lines** - Blank and comment-only lines are free; thresholds stay unchanged and messages name the metric.
- **Regenerate size baselines** - Accepted size findings resurface; run `vendor/bin/gruff-php analyse --generate-baseline --fail-on none`.
- **SARIF size identities change** - Both size rules emit new `gruffStableIdentity` values because their messages changed.
- **Refresh class-length hook baselines** - `size.class-length` gets a new hook identity; `size.file-length` stays matched.
- **Named arguments resolve at global sinks** - Rules read `header(header: $x)` like `header($x)`; method and constructor sinks stay positional.
- **Named guards still count as guards** - `simplexml_load_string(data: $xml, options: LIBXML_NONET)` reads as protected rather than unguarded.
- **`sqlsrv_query` accepts both parameter spellings** - Microsoft documents `tsql`, the bundled stub says `sql`; either resolves the query slot.
- **Procedural SQL sinks are covered** - Query text reaches the procedural drivers through one unambiguous same-scope assignment.
- **`proc_open()` argv arrays stay safe** - A direct argument vector is not shell text unless its first item launches a shell with a command flag.
- **Security baselines gain rows, not identities** - Existing messages and identities hold; review or baseline new procedural and named-argument rows.
- **Public-property checks cover promotion** - Readonly classes stay quiet; public mutable promotions now report and may add baseline findings.
- **Prophecy expectations stay configured** - Native promises, predictions, and asserted `reveal()` values no longer look like bare mocks.
- **Prophecy baselines shed false positives** - Obsolete groups disappear; remaining messages and `gruffStableIdentity` values stay stable.
- **Empty scans are unscored** - `analyse` emits `empty-analysis` and omits the score and branch-review delta when no PHP files are discovered.
- **Empty scans preserve exit policy** - The diagnostic is non-fatal, so zero-file runs still exit 0 without changing `--fail-on` behavior.
- **Empty-scan baselines are unchanged** - The diagnostic is not a finding, so it creates no `gruffStableIdentity` or baseline entry.
- **Promoted constructor docs stop duplicating** - Missing tags use `docs.missing-param-tag`, absent docblocks `docs.missing-public-phpdoc`.
- **Four rule options added** - Tune generic names, property line comments, dangerous functions, and intentional public-state classes.
- **Generated config explains its rules** - `init` writes each rule's description as its comment; size rules now name substantive-line counting.
- **Agent-hook parsers close bypasses** - Curl form headers, xargs flags, escaped filenames, and mixed-case env prefixes stop hiding protected paths.
- **Agent-hook batch scanning closes a gap** - Files past the first batch boundary are scanned, so a late protected path is not skipped.
- **`summary` applies the secret allowlist** - Vetted `allowlists.secretPreviews` findings no longer inflate its counts, grade, or rule table.

## 0.5.1 - 2026-07-20

0.5.1 removes the documented false-positive shapes while retaining their counterexamples: multiline regex ownership, exact named callback boundaries, clear Boolean state/proposition names, and short bounded pattern families gain conservative matching, while abbreviation and named-argument advisories gain machine-readable decision context. Rule IDs, severities, thresholds, scoring, `gruff.analysis.v2`, and `--fail-on` behaviour are unchanged. The bounded-group overflow message is deliberately more precise; its baseline impact is noted below.

- **Regex comments follow their statement** - `docs.regex-comment` supports multiline and contiguous calls; review baseline groups after upgrading.
- **Named callbacks stay named** - One-line checks exempt proven same-class callables; allow others with `options.allowedSymbols`.
- **Boolean names use word boundaries** - State suffixes and `requires` propositions pass; tune vocabularies or disable public API checks.
- **Constructor and abbreviation advice is quieter** - Named-argument advice skips constructors; `dto` and `utc` are accepted by default.
- **Findings classify remediation** - JSON, hook, and SARIF label fixes `APPLY` or `CONSIDER`; `CONFIGURE` remains reserved.
- **Pattern-family comments stay bounded** - Pattern or regex comments cover five contiguous constants; message changes may alter baselines.

## 0.5.0 - 2026-07-03

0.5.0 makes gruff's identities line-stable — baselines, branch review, and SARIF all stop churning when unrelated edits shift line numbers — sharpens eight rules against false positives and evasion gaps, brings `report` up to `analyse`'s workflow surface, and makes release version bumps drift-proof.

- **BREAKING: Baselines use `gruff.baseline.v2` groups** - Match file/rule/message counts; regenerate with `analyse --generate-baseline`.
- **BREAKING: Security profiles reject out-of-profile includes** - Remove the profile or include only security and sensitive-data rule IDs.
- **SARIF adds a stable partial fingerprint** - `gruffStableIdentity` keeps alerts open across unrelated line shifts.
- **Branch review tolerates line shifts** - Symbol-less findings use file/rule/message identity; duplicate occurrences still count separately.
- **Machine output survives invalid UTF-8** - JSON-based formats substitute bad bytes; valid finding hashes remain unchanged.
- **Security taint follows `.=` assignment** - Concatenated request data reaches shared security sinks; clean reassignment clears taint.
- **Unsafe XML loading requires an XML receiver** - Proven XML objects report; unrelated `open`, `load`, or `xml` calls stay silent.
- **Unsafe archive extraction tracks uploaded sources** - Request-controlled archives report with fixed destinations; entry names remain unproved.
- **Opaque dotted tokens no longer evade secret checks** - Only JWT-shaped literals are delegated; routes, versions, domains, and paths stay exempt.
- **Cognitive complexity counts `match`** - It scores the construct plus nested arms; affected message-keyed baselines may need regeneration.
- **Unused private methods respect dynamic dispatch** - Computed same-class calls suppress advice; foreign dynamic calls do not.
- **Throws docs stay in their own scope** - Throws in nested functions, closures, and anonymous classes no longer affect the outer method.
- **Negative Boolean names cover snake_case** - Whole-word `no_` and `not_` names report; prefixes inside words remain exempt.
- **Trend deltas compare like scopes** - Full-project and changed-scope histories stay separate; old entries remain readable.
- **`report` supports analyse workflows** - It forwards profile, changed-scope, new-finding, cache, Infection, and runtime options.
- **Version bumps update every stamp** - The bump script and preflight cover README and CLI summaries; golden tests derive the version.

## 0.4.1 - 2026-06-13

0.4.1 focuses on rule-rubric precision: fewer false positives and fewer over-severe findings without disabling the rules that catch real maintainability problems.

- **BREAKING: Removed `modernisation.enum-candidate`** - Constant-only classes no longer receive enum migration advice; findings disappear.
- **Rule rubrics are tighter** - Docs, naming, size, complexity, modernisation, and dead-code checks avoid known false-positive shapes.
- **Constant PHPDoc is configurable** - Meaningful comments pass by default; API constants can require PHPDoc globally or by path.
- **`report` rejects unknown rule IDs** - Invalid include/exclude filters exit 2 before prompting, writing config, or analysing.
- **Internal namespaces are consolidated** - Public CLI, config, schemas, and baselines stay stable; direct internal imports must be updated.

## 0.4.0 - 2026-06-11

0.4.0 retires the project rules whose whole-project analysis made per-edit feedback slow and whose verified false-positive rates made their findings untrustworthy on framework code. With no project rules left, the per-file result cache introduced in 0.3.0 now engages on every default run, and single-file scans no longer pay whole-project cost. Eight per-unit rules also gained precision fixes for mechanical misfires.

- **BEHAVIOUR CHANGE: Rule filters control execution** - Excluded rules do not run or score; unknown IDs are usage errors.
- **BREAKING: Removed `dead-code.unused-internal-{class,constant,function}`** - Delete their config; findings disappear.
- **BREAKING: Removed `design.single-implementor-interface`** - 45-100% false-positive rates: extension-point interfaces read as single-implementor.
- **Removed-rule config remains valid** - Unknown IDs under `rules:` warn and are ignored; `selection:` still rejects them.
- **Per-file cache is on by default** - Warm framework scans fell from 33–82s to 1.8–5.0s; the cap rose to 32,768 entries.
- **Single-file `analyse`/`hook` is no longer O(project)** - One-file scans dropped from 13-31s to under 0.1s on real framework repos.
- **Several rules misfire less** - Test bases, snake_case Boolean names, closure params, and superglobal writes receive intended handling.
- **Security checks exempt proven-safe shapes** - Variable includes accept fixed paths; SQL checks handle prepared queries and safe identifiers.
- **Sensitive-data checks skip safe fixtures** - Identifier literals, reserved-domain emails, and marked synthetic addresses are exempt.
- **Piped config-less runs no longer hang or write config** - The init prompt appears only in human-facing TTY output.

## 0.3.1 - 2026-06-09

0.3.1 adds the `gruff.hook.v1` agent-hook contract (`gruff-php hook --format json`) for editor and coding-agent integrations, plus one conservative test-quality rule, fixes Symfony YAML route and changed-region accounting edges in project-wide dead-code analysis, and moves the headline numbers to the top of text reports. No breaking changes; JSON schemas, config format, and baselines are unchanged.

- **Agent hooks emit `gruff.hook.v1` JSON** - Hooks report normalized, stable findings and support baseline, diff, and since comparisons.
- **Hook symbol scope is fairer** - Changed-range hooks omit file/project findings unless new against the baseline or diff base.
- **Changed symbol scope drops untouched aggregates** - Use `--changed-scope=file` to retain file/class aggregates for changed files.
- **Added `test-quality.static-analysis-redundant-test`** - Advisory findings flag tests that only restate same-file declarations.
- **Symfony YAML controllers count as live** - Internal FQCN controllers in `_controller` or `controller:` routes no longer look unused.
- **Suppression counts use changed files** - `suppressedCount` matches changed-file findings and also appears in `diff` JSON.
- **Text reports lead with score and findings** - `analyse` and `summary` show totals first and name the subcommand.

## 0.3.0 - 2026-05-31

0.3.0 focuses on agent-friendly CI: scan only changed code, respect ignored paths everywhere, and fail on newly introduced debt instead of old baseline debt. It also removes noisy complexity/design checks and tightens the rules that support human review of AI-written code.

- **Changed-code scanning** - `analyse` filters to edited ranges or symbols; JSON reports how many older findings were suppressed.
- **Ignore handling is stricter** - `paths.ignore` covers explicit files and changed scans; `check-ignore` explains matches.
- **Baseline reporting is clearer** - Output separates new, unchanged, and resolved findings to show debt movement.
- **Count-based gates** - `failureConditions:` can gate total or severity counts; `--fail-on` remains supported.
- **New-findings gate** - `--fail-on-new` fails only on findings introduced by the current change. It requires a baseline, `--diff-vs`, or both.
- **Incremental cache** - `.gruff-cache/` reuses unchanged-file findings; project-wide analysis bypasses it.
- **Config presets** - `extends: gruff.recommended`, `gruff.starter`, or `gruff.strict` can replace most local config boilerplate.
- **BREAKING: Removed `complexity.npath`** - Delete its config and regenerate baselines; clearer complexity rules remain.
- **BREAKING: Removed synthetic `design.god-method` findings** - Component findings remain; remove stale baseline entries.
- **Fairer scoring** - Correlated size and complexity findings share one penalty but remain individually visible.
- **Boolean-name allowlist** - `naming.boolean-prefix` can now accept intentional names like `valid()` without forcing a public API rename.
- **Complexity rules recalibrated** - Halstead and maintainability are advisory; cognitive/nesting tighten; cyclomatic stays warning.
- **Fake-test rules are stricter** - Tests with no real assertion, no subject call, or tautological type assertions now fail at error level.
- **Config supports `advisory`** - Rule severity overrides now accept `advisory`.
- **More dead-code checks** - gruff can now flag unused private constants and unused project-owned internal classes, functions, and constants.
- **More secret checks** - Detects GCP service-account keys and HTTP(S) URL credentials while reporters redact raw values.
- **Mission documented** - README, docs, agent instructions, and ADR-017 now state the project goal: help humans verify AI-written code.
- **PHPDoc mixed rule relaxed** - Nullable JSON bags such as `array<string, mixed>|null` no longer trigger `phpdoc-mixed-overuse`.
- **Internal cleanup** - Large command and analysis classes were split up. CLI behaviour and output schemas are unchanged.
- **`docs.return-comment` changed meaning** - `@return` tags need a real description; regenerate affected baselines.
- **`docs.missing-param-tag` covers more methods** - Documented private/protected methods and functions now require tags.

## 0.2.0 - 2026-05-28

0.2.0 makes CI policy more explicit, adds rule triage help, and introduces several breaking config/schema changes.

- **BREAKING: `schemaVersion:` is required** - Add `schemaVersion: gruff-php.config.v0.1` to `.gruff-php.yaml`, or run `gruff-php init --force`.
- **BREAKING: `analyse --fail-on` defaults to advisory** - Use `--fail-on error` or set `minimumSeverity.analyse: error` to restore it.
- **BREAKING: JSON schemas moved to v2** - `summary` and `analyse` now emit v2 schemas and singular severity count keys. Update JSON consumers.
- **BREAKING: Removed `naming.parameter-type-name`** - Delete any config for this rule. Findings disappear automatically.
- **BREAKING: `waste.one-line-method` defaults tightened** - Most projects see fewer findings. Pin the old options only if you need the old behaviour.
- **Per-command severity config** - `minimumSeverity:` lets config set fail thresholds for `analyse`, `report`, and `dashboard`.
- **Visibility-only rules** - `excludeFromScore: true` keeps a rule visible in reports without affecting scores.
- **Rule triage help** - `list-rules <id>` shows options, escapes, and false-positive notes; text reports point to `summary`.
- **Stable finding identity** - JSON adds a line-shift-resistant `stableIdentity` field for external diff tooling.
- **Fewer mixed-type false positives** - Precise `array{...}` PHPDoc shapes with useful sibling fields no longer trip `phpdoc-mixed-overuse`.
- **Cleaner first run** - `init` now seeds common abbreviations, reports are easier to scan, and rule messages point at config escape hatches.
- **Bug fixes** - Fixed report/dashboard fail-threshold loading, abbreviation defaults, and `@param-out` / `@param-immutable` handling.
- **Regression tests** - Added coverage for fail-threshold parsing, precedence, report hints, and new accessors.

## 0.1.3 - 2026-05-24

Patch release for Composer installs.

- **Installed binary fixed** - `vendor/bin/gruff-php` now finds Composer's generated autoload path in consuming projects.
- **Packaging regression test** - Tests now cover installing and running `vendor/bin/gruff-php init` from a throwaway project.

## 0.1.2 - 2026-05-24

Harness and documentation maintenance for goat-flow 1.7.0.

- **Agent instructions updated** - Codex and Claude docs now use the packaged goat-flow audit CLI and list the real project quality surface.
- **Security references cleaned up** - Old goat-security stubs now redirect to the current identity/data and supply-chain/CICD references.
- **Architecture docs refreshed** - Code map and architecture docs now cover the current CLI and local workflow.
- **Hook fixtures fixed** - Dangerous-command hook tests now use the real fixture repository name.
- **Symfony YAML range widened** - Runtime support now matches the other Symfony components: `^6.4 || ^7.0 || ^8.0`.

## 0.1.1 - 2026-05-24

Onboarding-focused follow-up to 0.1.0.

- **`init` command added** - `gruff-php init` creates `.gruff-php.yaml`, preserves ignore patterns with `--force`, and supports `--project-root`.
- **Missing-config prompt** - TTY runs can offer to create config before scanning.
- **More test-quality rules enabled** - New default advisory rules catch common weak-test patterns.
- **Baseline guidance added** - `summary` now points users to baseline generation and no-baseline audit modes.
- **Dependency audit added** - Composer audit now runs in `composer check` and CI.
- **Docs expanded** - README and docs now cover rules, CI, config, output formats, dashboard, naming, and release process.

## 0.1.0 - 2026-05-23

First public release.

- **120 rules** - Covers size, complexity, maintainability, dead code, naming, docs, modernisation, security, sensitive data, tests, and design.
- **Five commands** - `analyse`, `summary`, `report`, `dashboard`, and `list-rules`.
- **Seven output formats** - `text`, `json`, `html`, `markdown`, `github`, `hotspot`, and `sarif`.
- **Strict YAML config** - `.gruff-php.yaml` supports baselines, branch review, mutation analysis, and dashboard settings.
- **PHP 8.3 and MIT** - Minimum runtime is PHP 8.3.0; license is MIT.
