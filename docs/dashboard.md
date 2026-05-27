# Dashboard

`vendor/bin/gruff-php dashboard` serves a local browser dashboard for repeated
analysis.

## Start

```sh
vendor/bin/gruff-php dashboard --host=127.0.0.1 --port=8765 --project=.
```

`--project-root` is accepted as an alias for `--project`.

## Options

| Option | Default | Purpose |
| --- | --- | --- |
| `--host` | `127.0.0.1` | Bind host. |
| `--port` | `8765` | Bind port. |
| `--project`, `--project-root` | current directory | Initial project root. |
| `--scan-timeout` | `120` | Per-scan timeout in seconds; `0` disables. |
| `--fail-on` | `none` | Initial fail threshold. |
| `--config` | auto | Initial config path. |
| `--no-config` | false | Skip config discovery. |
| `--baseline` | empty/default | Initial baseline path. |
| `--no-baseline` | false | Skip baseline application. |
| `--diff` | false | Start in diff-only mode. |
| `--include-ignored` | false | Include ignored files. |

## Initial Fail Threshold

The `Fail on` `<select>` defaults to `none` unless the project config carries
`minimumSeverity.dashboard:`. Resolution order:

1. CLI `--fail-on` flag at `dashboard` launch time (when set explicitly)
2. `minimumSeverity.dashboard` in `.gruff-php.yaml`
3. Binary default `none`

Submitting the form posts the selected value as `failOn`, which is forwarded
to the analyse subprocess as `--fail-on`. The option list itself
(`none | advisory | warning | error`) is fixed.

```yaml
schemaVersion: gruff-php.config.v0.1
minimumSeverity:
  dashboard: warning
```

See [`docs/configuration.md`](configuration.md#minimum-severity) for the
rejection contract and the precedence rule.

## Safety

The dashboard has no authentication and should stay bound to loopback unless the
network is trusted. Treat the bind address as the safety boundary.

## Polyglot Repos

`gruff-go`, `gruff-php`, and `gruff-py` default to port `8765`. Use `--port`
when running multiple dashboards at once.
