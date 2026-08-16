# ADR-032: Ratify security, sensitive-data, and missing-PHPDoc severities

**Status:** Proposed
**Date:** 2026-08-11
**Target:** Family ratification for 0.5.2; any runtime change belongs in a future minor release
**Readiness:** Ready for an operator decision on the constrained deferral proposed here
**Relates to:** [ADR-017 mission](ADR-017-mission-govern-ai-generated-code.md), [ADR-022 test-quality gate parity](ADR-022-test-quality-gate-parity.md), [ADR-029 baseline matching](ADR-029-baseline-v2-group-count-matching.md)

## Context

The family contract leaves two severity forks open. `<workspace>/FAMILY-CONTRACT.md` §12 names PHP's `sensitive-data.*` warnings versus Go, Rust, and TypeScript errors. It also records the wider split between Go's advisory-heavy security model, PHP and Rust's uniform warnings, and TypeScript's selected errors.

PHP currently reports every `security.*` and `sensitive-data.*` finding at warning or below, while `docs.missing-public-phpdoc` is an error. This ordering matters at an error-threshold gate. The shipped `analyse` default remains advisory, so the current ordering does not make a default scan pass security findings. Operators can, however, select `--fail-on=error` or configure an error minimum.

The ten-repository corpus contains 6,223 `security.*` findings, 1,709 `sensitive-data.*` findings, and 80,430 `docs.missing-public-phpdoc` findings. Missing PHPDoc accounts for 430 of Slim's 450 errors and 2,091 of guzzle's 2,169 errors. The sensitive-data total needs further calibration: 1,573 findings, or 92.0%, come from five medium-confidence rules.

No severity changes are part of the 0.5.2 remediation. The repository's stability contract treats exit-sensitive changes as compatibility-sensitive and routes them to a future minor release.

## Decision

This proposal asks the operator to ratify a constrained deferral:

1. Keep all PHP severities unchanged for 0.5.2.
2. Reject namespace-wide promotion of `sensitive-data.*` or `security.*`. Confidence metadata and finding counts do not prove hard-gate precision or exploitability.
3. Use the six high-confidence sensitive-data rules as the first bounded calibration cohort, not as an approved error set. They affect 136 findings in this corpus.
4. Keep `docs.missing-public-phpdoc` at error until an unsaturated documentation corpus and an explicit mission decision support a different ordering.
5. Require any future proposal to name exact rule IDs, supply rule-level precision and exploitability evidence, measure adopter gates, and target a future minor release.

The operator decision is whether to ratify this deferral and its evidence gates. Ratification does not authorize a severity edit. A later package may propose exact promotions or a PHPDoc demotion after it closes the evidence gaps above.

Ratification belongs in the family contract. A port-local ADR may carry the evidence and recommendation, but it cannot authorize the family policy.

## Measurement method and provenance

The 2026-08-12 replay used the immutable gruff-php PR head `c202c771a76ec50bb5e26d88f7664008288305c2` plus the uncommitted analyzer-source delta under review. The complete `src/` delta used for the replay had SHA-256 `6d80163c4e0c0a25004a7ec77e9478f0b90d8ed606488d02ea186923ae40edb3`.

Each target ran from an empty external directory so target-local config could not alter policy:

```bash
php <workspace>/gruff-php/bin/gruff-php analyse \
  --no-config --no-cache --fail-on=none --format=json \
  <workspace>/test-scan-repos/php/<repo>
```

The replay changed only selected finding severities, then recalculated summary counts and scores with the production weights and rounding. `<workspace>/gruff-php-corpus-runner/calibrated-severity-options.jq`, SHA-256 `4d98d2bd835fa03d127fc34a0629754dc34694e2178ca8da9384269370556a84`, modelled the sensitive-data and PHPDoc scenarios. `<workspace>/gruff-php-corpus-runner/severity-options.jq`, SHA-256 `da4dd432f4bcee94e8364193e1c284c88e8ad27013df4800e9a389cb75c9ce8d`, modelled the blanket-security scenarios.

The scan JSON was streamed into the aggregation and was not retained as a durable artifact. The committed analyzer head and corpus revisions are immutable, but the working-tree analyzer delta is represented only by a digest until the operator commits it. These measurements support the deferral; they are not sufficient provenance for a future severity implementation. Before proposing one, rerun from an immutable analyzer revision and retain the normalized per-rule result artifact with the family decision package.

The target revisions were:

| Repository | Revision |
| --- | --- |
| DVWA | `209930b26ef16b1636dfac74ca49b5557fd0528e` |
| Slim | `80900fb39cafce3ae53b18a2c4f642a122f03095` |
| WordPress | `7bd547f191058ca40f49eaf44711a35f4e2533e6` |
| framework | `8df67f9d176d1d0375a866d8c6780be95ce0336e` |
| guzzle | `d1cbca76970939a9c2ced55b1e25ea26f34fc773` |
| mutillidae | `84f2c00d9141dbb9e26a448c8288e651e0b5bb04` |
| orm | `a4d13ed5b11e7f7b4d654b1adf95432031ae3ffc` |
| phpmyadmin | `be5451492d924af4cce14aa17775673bd5dc4ffd` |
| phpunit | `6917e76ff5762f4b70203b58608f6bba360cc2c2` |
| symfony | `daf7477863ede5047876c272148c80d5a3b46664` |

Framework, PHPUnit, and Symfony emitted parse diagnostics. Their findings and scores are included, but their process exits are not severity-gate evidence.

## Sensitive-data calibration

| Confidence | Rule | Findings |
| --- | --- | ---: |
| High | `sensitive-data.api-key-pattern` | 2 |
| High | `sensitive-data.aws-access-key` | 0 |
| High | `sensitive-data.database-url-password` | 29 |
| High | `sensitive-data.gcp-service-account-key` | 0 |
| High | `sensitive-data.private-key` | 3 |
| High | `sensitive-data.url-credentials` | 102 |
| **High subtotal** |  | **136** |
| Medium | `sensitive-data.hardcoded-env-value` | 13 |
| Medium | `sensitive-data.high-entropy-string` | 350 |
| Medium | `sensitive-data.jwt-token` | 1 |
| Medium | `sensitive-data.phi-pattern` | 0 |
| Medium | `sensitive-data.pii-test-fixture` | 1,209 |
| **Medium subtotal** |  | **1,573** |
| **Total** |  | **1,709** |

## Measured blast radius

`High sens E` promotes only the six high-confidence sensitive-data rules. `All sens E` promotes all eleven. `Docs W` demotes missing public PHPDoc. The split columns combine each sensitive-data option with the documentation demotion.

| Repository | High sens | All sens | Missing PHPDoc | Security | Current | High sens E | All sens E | High split | All split |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| DVWA | 0 | 4 | 117 | 137 | 23.4 | 23.4 | 18.2 | 23.4 | 18.2 |
| Slim | 0 | 0 | 430 | 49 | 17.7 | 17.7 | 17.7 | 17.7 | 17.7 |
| WordPress | 0 | 15 | 434 | 970 | 10.0 | 10.0 | 10.0 | 10.0 | 10.0 |
| framework | 22 | 1,214 | 16,253 | 924 | 0.0 | 0.0 | 0.0 | 0.0 | 0.0 |
| guzzle | 52 | 67 | 2,091 | 571 | 0.0 | 0.0 | 0.0 | 0.0 | 0.0 |
| mutillidae | 0 | 3 | 161 | 361 | 16.4 | 16.4 | 10.0 | 16.4 | 10.0 |
| orm | 0 | 57 | 5,958 | 79 | 0.0 | 0.0 | 0.0 | 0.0 | 0.0 |
| phpmyadmin | 4 | 133 | 2,726 | 442 | 0.0 | 0.0 | 0.0 | 0.0 | 0.0 |
| phpunit | 0 | 1 | 8,097 | 225 | 8.8 | 8.8 | 6.4 | 8.8 | 6.4 |
| symfony | 58 | 215 | 44,163 | 2,465 | 0.0 | 0.0 | 0.0 | 0.0 | 0.0 |

| Option | Finding tiers changed | Numeric composite moves | Total error findings after replay |
| --- | ---: | ---: | ---: |
| Status quo | 0 | 0 | 85,400 |
| High-confidence sensitive data to error | 136 | 0 | 85,536 |
| All sensitive data to error | 1,709 | 3 | 87,109 |
| Missing public PHPDoc to warning | 80,430 | 0 | 4,970 |
| High-confidence split | 80,566 | 0 | 5,106 |
| Blanket sensitive-data split | 82,139 | 3 | 6,679 |
| All security to error | 6,223 | 0 | 91,623 |
| All security and sensitive data to error; PHPDoc to warning | 88,362 | 3 | 12,902 |

All three blanket-sensitive composite moves remain grade F: DVWA moves from 23.4 to 18.2, mutillidae from 16.4 to 10.0, and PHPUnit from 8.8 to 6.4. The high-confidence candidate changes no composite. Missing-PHPDoc demotion also changes no composite because each documentation pillar remains floored at zero under other documentation findings.

Score saturation is not evidence that the policy is harmless. Severity controls CI independently of the composite.

## Gate outcomes

All ten repositories already fail at error, warning, and advisory thresholds in every replay. The measured corpus therefore produces zero pass-to-fail or fail-to-pass transitions for those three thresholds.

| Option | Current error failures | Replayed error failures | Measured transitions |
| --- | ---: | ---: | ---: |
| High-confidence sensitive data to error | 10 | 10 | 0 |
| All sensitive data to error | 10 | 10 | 0 |
| Missing public PHPDoc to warning | 10 | 10 | 0 |
| High-confidence split | 10 | 10 | 0 |
| Blanket sensitive-data split | 10 | 10 | 0 |
| All security to error | 10 | 10 | 0 |
| Full blanket option | 10 | 10 | 0 |

This table does not prove zero adopter impact. A project that currently has warnings but no errors can start failing an error-threshold gate after a promotion. A project whose only errors are missing-PHPDoc findings can stop failing after a demotion. `--fail-on-new` cannot be measured from this corpus without a reference baseline or diff.

## Baseline and migration cost

Severity is absent from the v2 baseline group key, so accepted findings keep matching when only their severity changes. That protection is partial:

| Adopter surface | Severity-only effect |
| --- | --- |
| Existing accepted finding | Remains suppressed when file, rule id, and message still match. |
| New finding | Uses the new severity and can change `--fail-on` or `--fail-on-new`. |
| Group count above accepted count | Overflow findings surface at the new severity. |
| Summary, JSON, SARIF, annotations | Tier counts and rendered levels change. |
| Score | Severity weight changes and can move pillar or composite scores. |
| Stable identity | Remains stable only if rule id, file, symbol, and message remain unchanged. |

Keep finding messages unchanged during any severity migration. `Finding::stableIdentity()` includes the message but not severity. ADR-029 baseline groups also include the message but not severity. A message edit would turn a tier migration into an identity and baseline migration.

## Controls before any future severity change

A future proposal and implementation need all of these controls:

1. Supply reviewed true-positive, false-positive, and exploitability evidence for every proposed rule ID; registry confidence is not a substitute.
2. Select exact rule IDs in the family contract; do not encode a namespace-wide promotion.
3. Make each sensitive-data rule definition authoritative for emitted severity. `SecretScannerHelper::finding()` currently stamps `Severity::Warning`, which can drift from registry metadata.
4. Keep finding messages and stable-identity inputs unchanged.
5. Add per-rule severity assertions and refresh the rule-definition and fixture-finding snapshots.
6. Preview the self-scan under the repository's `.gruff-php.yaml`, then run the full advisory self-scan after refreshing any corpus hash.
7. Test `--fail-on=error`, `--fail-on=warning`, `--fail-on-new`, baseline overflow, JSON, and SARIF behavior.
8. Publish a `BREAKING:` changelog entry with exact migration commands and a family-contract update.
9. Release in a future minor version, never as part of the 0.5.2 remediation.

## Failure Mode Comparison

| Option | What fails | Verdict |
| --- | --- | --- |
| Keep current severities indefinitely | High-confidence credential shapes never enter an error-threshold gate in PHP, unlike three sibling ports. | Defer now, then revisit with rule-level evidence. |
| Promote all sensitive data | Medium-confidence rules supply 92.0% of the affected findings without rule-level precision evidence. | Rejected as a blanket option. |
| Promote high-confidence sensitive data | Moves 136 credential-shaped findings, but nominal confidence does not prove hard-gate suitability. | Calibration cohort only; not approved for promotion. |
| Demote missing public PHPDoc | Removes 80,430 error tiers, but the saturated corpus cannot show whether the new documentation ordering matches the mission. | Deferred pending better evidence. |
| Promote all security | Moves 6,223 heuristic findings without a family concept-level hard-gate set. | Rejected as a batch. |
| Change severities in 0.5.2 | Introduces compatibility-sensitive exit behavior inside the current patch release. | Rejected. |

## Consequences

- The 0.5.2 remediation ships without a severity or exit-policy change.
- The family gets a measured, rule-level calibration cohort instead of a namespace-wide promotion.
- Missing public PHPDoc remains an error until later evidence supports an explicit mission choice.
- The corpus remains useful for tier and score blast radius, but not for representative adopter gate transitions.
- Ratifying this ADR records the deferral and evidence gates; it does not authorize implementation.

## Reversibility

The deferral is a two-way policy door: the family can supersede it when the missing evidence exists, with no adopter migration in the meantime. Once a later release changes severities, rollback becomes costly and must restore definitions and emitted tiers together, refresh snapshots, rerun gate and baseline tests, update the family contract, and publish the inverse migration note.

Revisit the high-confidence set when corpus precision evidence changes. Revisit missing-PHPDoc severity with a corpus that includes projects whose documentation pillar is not already score-saturated.
