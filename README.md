# gruff-php

Opinionated PHP code quality analyser. Flags code that runs but is bloated, unclear, or hard to maintain. Not a type checker or linter &mdash; use PHPStan and PHP_CodeSniffer for those.

## Install

```bash
composer require --dev devgoat/gruff-php
```

Requires PHP 8.3+.

## Usage

```bash
# Analyse a directory
php bin/gruff analyse src/

# Analyse specific files
php bin/gruff analyse src/Service/UserService.php src/Controller/

# JSON output for CI
php bin/gruff analyse src/ --format=json

# Fail on warnings (default: fail on errors only)
php bin/gruff analyse src/ --fail-on=warning

# Custom config
php bin/gruff analyse src/ --config=gruff.json
```

### Exit codes

| Code | Meaning |
|------|---------|
| 0 | Clean or below fail threshold |
| 1 | Findings meet or exceed `--fail-on` threshold |
| 2 | Config error, parse error, or invalid input |

## Rules

All rules use two-tier thresholds (warning / error). Override per-project via a JSON config file.

### Size

| Rule | Default (warn / error) | What it flags |
|------|------------------------|---------------|
| `size.file-length` | 400 / 800 | Files with too many lines |
| `size.class-length` | 300 / 500 | Classes, traits, or enums that are too long |
| `size.method-length` | 30 / 60 | Methods, functions, or closures that are too long |
| `size.parameter-count` | 5 / 8 | Too many parameters (includes promoted properties) |
| `size.public-method-count` | 15 / 25 | Classes exposing too many public methods |
| `size.property-count` | 15 / 25 | Classes with too many properties (includes promoted) |
| `size.average-method-length` | 20 / 40 | Classes where methods are consistently long |

### Complexity

| Rule | Default (warn / error) | What it flags |
|------|------------------------|---------------|
| `complexity.cyclomatic` | 10 / 20 | Too many branching paths |
| `complexity.cognitive` | 15 / 30 | Hard to read (Sonar-style algorithm) |
| `complexity.nesting-depth` | 4 / 6 | Too deeply nested |
| `complexity.npath` | 200 / 500 | Too many independent execution paths |
| `complexity.halstead-volume` | 1000 / 2000 | High information content |
| `complexity.maintainability-index` | 65 / 40 | Low maintainability (below threshold = bad) |

## Configuration

Create a `gruff.json` in your project root:

```json
{
    "rules": {
        "size.method-length": {
            "thresholds": { "warning": 40, "error": 80 }
        },
        "complexity.cyclomatic": {
            "enabled": false
        }
    }
}
```

## Development

```bash
composer install
composer check    # Syntax + PHPStan level 10
composer test     # PHPUnit
```

## License

MIT